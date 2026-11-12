<?php

namespace AP;

defined('ABSPATH') || exit;

class Ajax
{
  public static function register()
  {
    add_action('wp_ajax_es_handle_attendance',       [__CLASS__, 'handle_attendance']);
    add_action('wp_ajax_nopriv_es_handle_attendance', [__CLASS__, 'handle_attendance']);
    add_action('wp_ajax_es_filter_attendance',       [__CLASS__, 'filter_attendance']);
    add_action('wp_ajax_es_export_attendance_csv',   [__CLASS__, 'export_csv']);
    add_action('wp_ajax_handle_member_status_update', [__CLASS__, 'update_member']);
    add_action('wp_ajax_get_attendance_info',        [__CLASS__, 'get_attendance_info']);

    add_action('wp_ajax_ap_first_timers_query',  [__CLASS__, 'first_timers_query']);
    add_action('wp_ajax_nopriv_ap_first_timers_query',  [__CLASS__, 'first_timers_query']);

    add_action('wp_ajax_ap_first_timers_export', [__CLASS__, 'first_timers_export']);
    add_action('wp_ajax_nopriv_ap_first_timers_export', [__CLASS__, 'first_timers_export']);

    add_action('wp_ajax_ap_get_nonce',      ['AP\\Ajax', 'get_nonce']);
    add_action('wp_ajax_nopriv_ap_get_nonce', ['AP\\Ajax', 'get_nonce']);

    add_action('wp_ajax_ap_check_phone_exists', ['AP\\Ajax', 'check_phone_exists']);
    add_action('wp_ajax_nopriv_ap_check_phone_exists', ['AP\\Ajax', 'check_phone_exists']);

    // 新增的 AJAX 动作
    add_action('wp_ajax_es_quick_attendance', [__CLASS__, 'quick_attendance']);
    add_action('wp_ajax_nopriv_es_quick_attendance', [__CLASS__, 'quick_attendance']);

    // 同日换堂：把今天的签到移到另一个堂
    add_action('wp_ajax_es_move_attendance', [__CLASS__, 'move_attendance']);
    add_action('wp_ajax_nopriv_es_move_attendance', [__CLASS__, 'move_attendance']);

    add_action('wp_ajax_ap_get_user_profile', [__CLASS__, 'get_user_profile']);
    add_action('wp_ajax_nopriv_ap_get_user_profile', [__CLASS__, 'get_user_profile']);

    add_action('wp_ajax_ap_update_profile', [__CLASS__, 'update_profile']);
    add_action('wp_ajax_nopriv_ap_update_profile', [__CLASS__, 'update_profile']);

    // 新增日志专用接口（仅登录用户）
    add_action('wp_ajax_ap_first_timers_log_query',  [__CLASS__, 'first_timers_log_query']);
    add_action('wp_ajax_ap_first_timers_log_export', [__CLASS__, 'first_timers_log_export']);

    // 新来宾记录专用接口
    add_action('wp_ajax_ap_newcomers_query',  [__CLASS__, 'newcomers_query']);
    add_action('wp_ajax_ap_newcomers_export', [__CLASS__, 'newcomers_export']);

    add_action('wp_ajax_ap_delete_newcomer', [__CLASS__, 'handle_delete_newcomer']);
  }

  public static function get_nonce()
  {
    // 给当前访客发一个 fresh nonce，不改登录状态
    wp_send_json_success(['nonce' => wp_create_nonce('es_attendance_nonce')]);
  }

  private static function verify_nonce()
  {
    check_ajax_referer('es_attendance_nonce', 'nonce');
  }

  /** 后台类接口：nonce + 权限检查，不通过直接 403 */
  private static function require_cap(bool $allowed)
  {
    self::verify_nonce();
    if (!$allowed) {
      wp_send_json_error(['message' => 'Permission denied'], 403);
    }
  }

  /**
   * 管理类接口的堂范围：管理员可用 POST congregation 筛选（空 = 全部堂）；
   * 同工一律是自己的所属堂（忽略 POST）。调用前须已通过 require_cap。
   */
  private static function request_scope(): ?string
  {
    $requested = isset($_POST['congregation']) ? sanitize_text_field(wp_unslash($_POST['congregation'])) : null;
    return Access::scope($requested);
  }

  /** 从请求取堂：带了无效的堂代号 → 400 */
  private static function request_congregation(): string
  {
    $c = Congregation::from_request($_POST);
    if ($c === false) {
      wp_send_json_error(['code' => 'invalid_congregation', 'message' => t('invalid_congregation', Congregation::DEFAULT)], 400);
    }
    return $c;
  }

  /** 签到类结果：message 字段保留给旧版前端 */
  private static function send_check_in_result(array $res): void
  {
    $res['message'] = $res['msg'];
    if ($res['ok']) wp_send_json_success($res);
    wp_send_json_error($res);
  }

  public static function handle_attendance()
  {
    self::verify_nonce();
    $c = self::request_congregation();
    self::send_check_in_result(Attendance_DB::handle_submit($_POST, $c));
  }

  public static function move_attendance()
  {
    self::verify_nonce();
    $c = self::request_congregation();
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    self::send_check_in_result(Attendance_DB::move_today($phone, $c));
  }

  public static function filter_attendance()
  {
    self::require_cap(ap_user_can_view());
    $scope = self::request_scope();
    $rows = \AP\Attendance_DB::query_filtered($_POST, $scope);

    $is_frontend = !empty($_POST['fe']) && $_POST['fe'] == '1';

    ob_start();
    if ($is_frontend) {
      // 前台：表格 + 分页
      $paged = max(1, absint($_POST['paged'] ?? 1));
      list($rows_page, $page, $pages) = \AP\Admin_Page::paginate_array($rows, $paged, AP_FRONT_PER_PAGE);
      \AP\Admin_Page::render_table_simple($rows_page, $scope);
      \AP\Admin_Page::render_pagination_simple($page, $pages);
    } else {
      // 后台：WP_List_Table + loader
      \AP\Admin_Page::render_table($rows);
      echo '<div id="loader-box" style="display:none;"><div id="es-loading-spinner" class="loader"></div></div>';
    }
    wp_send_json_success(['table_html' => ob_get_clean()]);
  }

  public static function export_csv()
  {
    self::require_cap(ap_user_can_view());
    $csv = Attendance_DB::build_csv($_POST, self::request_scope(), Access::is_admin());
    header('Content-Type: text/csv; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";
    echo $csv;
    wp_die();
  }

  public static function update_member()
  {
    self::require_cap(ap_user_can_manage());
    $msg = Attendance_DB::bulk_update_member($_POST, self::request_scope());
    wp_send_json_success(['message' => $msg]);
  }

  public static function get_attendance_info()
  {
    self::require_cap(ap_user_can_view());
    $id   = absint($_POST['attendance_id'] ?? 0);
    $start = sanitize_text_field($_POST['start_date_filter'] ?? '');
    $end  = sanitize_text_field($_POST['end_date_filter'] ?? '');
    $data = Attendance_DB::get_detail($id, $start, $end, self::request_scope());
    $show_c = true; // 所有人都显示每次签到在哪个堂（本堂会友去别的堂也看得到）
    $lang = ap_ui_lang();

    ob_start(); ?>
    <div class="ap-modal-header">
      <button type="button" class="close" aria-label="Close"></button>
    </div>
    <div><?php echo esc_html(($data['user']->first_name ?? '') . ' ' . ($data['user']->last_name ?? '')); ?></div>
    <p><?php echo esc_html($data['user']->phone ?? ''); ?></p>
    <p><?php echo esc_html(\AP\format_date_dmy($start)); ?> to <?php echo esc_html(\AP\format_date_dmy($end)); ?></p>
    <div style="max-height:300px; overflow-y:auto; margin-top:10px;">
      <table style="width:100%; border-collapse:collapse;">
        <thead style="position:sticky; top:0; background:#f9f9f9;">
          <tr>
            <th style="border:1px solid #ccc; padding:6px;">Date Attended</th>
            <?php if ($show_c): ?>
              <th style="border:1px solid #ccc; padding:6px;"><?php echo esc_html(I18n::t('column_congregation', $lang)); ?></th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data['list'] as $row): ?>
            <tr>
              <td style="border:1px solid #ccc; padding:6px;">
                <?php echo esc_html(\AP\format_date_dmy($row['date_attended'])); ?>
              </td>
              <?php if ($show_c): ?>
                <td style="border:1px solid #ccc; padding:6px;"><?php echo esc_html(Congregation::label($row['congregation'], $lang)); ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php
    echo ob_get_clean();
    wp_die();
  }

  public static function check_phone_exists()
  {
    // 校验 nonce（前端用 esAjax.nonce）
    check_ajax_referer('es_attendance_nonce', 'nonce');

    // 取参数并做与提交时一致的归一化
    $cc  = isset($_POST['cc'])  ? sanitize_text_field($_POST['cc'])  : '';
    $num = isset($_POST['num']) ? sanitize_text_field($_POST['num']) : '';
    $num = preg_replace('/[\s\-\(\)]/', '', $num ?: '');

    // AU 本地：+61 去掉开头 0（与 handle_submit 一致）
    if ($cc === '+61' && substr($num, 0, 1) === '0') {
      $num = substr($num, 1);
    }
    $phone = $cc . $num;

    if ($phone === '' || $cc === '' || $num === '') {
      wp_send_json_success(['exists' => false]); // 无效输入按不存在处理
    }

    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';
    $exists = (bool) $wpdb->get_var(
      $wpdb->prepare("SELECT 1 FROM {$attendance} WHERE phone = %s LIMIT 1", $phone)
    );

    // 不缓存
    if (!headers_sent()) {
      nocache_headers();
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
      header('Pragma: no-cache');
      header('Expires: 0');
      header('Vary: Cookie');
    }

    wp_send_json_success(['exists' => $exists]);
  }

  /**
   * 快速签到（仅凭电话号码）
   */
  public static function quick_attendance()
  {
    self::verify_nonce();
    $c = self::request_congregation();
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    self::send_check_in_result(Attendance_DB::quick_check_in($phone, $c));
  }

  /**
   * 获取用户资料（修改资料用；邮箱只返回打码版本）
   */
  public static function get_user_profile()
  {
    self::verify_nonce();
    $c = self::request_congregation();

    $phone = sanitize_text_field($_POST['phone'] ?? '');
    if (!$phone) {
      wp_send_json_error(['code' => 'phone_required', 'message' => t('phone_required', $c)]);
    }

    $profile = Attendance_DB::get_profile($phone);
    if (!$profile) {
      wp_send_json_error(['code' => 'not_registered', 'message' => t('profile_not_found', $c)]);
    }
    wp_send_json_success($profile);
  }

  /**
   * 更新用户资料（含所属堂；团契需与所属堂匹配）
   */
  public static function update_profile()
  {
    self::verify_nonce();
    $c = self::request_congregation();
    $res = Attendance_DB::update_profile($_POST, $c);
    $res['message'] = $res['msg'];
    if ($res['ok']) wp_send_json_success($res);
    wp_send_json_error($res);
  }

  // ===================== 新来宾 / 首访名单 =====================

  /** 名单类接口：须登录且有权限（管理员或已指定堂），nonce 正确 */
  private static function require_list_access(bool $json = true): void
  {
    if (!headers_sent()) nocache_headers();
    if (!Access::can_access()) {
      if ($json) wp_send_json_error(['message' => 'Permission denied'], 403);
      status_header(403);
      header('Content-Type: text/plain; charset=utf-8');
      echo 'Forbidden';
      exit;
    }
    check_ajax_referer('es_attendance_nonce', 'nonce');
  }

  /** @return array{0:string,1:string} */
  private static function request_range(): array
  {
    $today = ap_today();
    $start = ap_valid_ymd(sanitize_text_field(wp_unslash($_POST['start'] ?? ''))) ?? $today;
    $end   = ap_valid_ymd(sanitize_text_field(wp_unslash($_POST['end'] ?? ''))) ?? $today;
    return [$start, $end];
  }

  private static function send_csv(string $filename, array $head, array $lines): void
  {
    if (!headers_sent()) {
      nocache_headers();
      header('Content-Type: text/csv; charset=UTF-8');
      header('X-Content-Type-Options: nosniff');
      header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
    }
    $out = fopen('php://output', 'w');
    echo "\xEF\xBB\xBF";
    fputcsv($out, $head);
    foreach ($lines as $l) fputcsv($out, $l);
    fclose($out);
    exit;
  }

  /** 首访名单（按全教会第一次出席日期） */
  public static function first_timers_query(): void
  {
    self::require_list_access();
    [$start, $end] = self::request_range();
    $rows = Attendance_DB::query_first_timers($start, $end, self::request_scope());
    wp_send_json_success([
      'html'         => Frontend_Page::render_first_timers_list_html($rows, true, false, false, ap_ui_lang(), false),
      'generated_at' => date_i18n('Y-m-d H:i', current_time('timestamp')),
      'count'        => count($rows),
    ]);
  }

  public static function first_timers_export(): void
  {
    self::require_list_access(false);
    [$start, $end] = self::request_range();
    $lang = ap_ui_lang();
    $rows = Attendance_DB::query_first_timers($start, $end, self::request_scope());
    $lines = [];
    foreach ($rows as $r) {
      $lines[] = [
        $r['first_name'] ?? '',
        $r['last_name'] ?? '',
        format_date_dmy($r['first_attendance_date'] ?? ''),
        ap_translate_referral_source($r['referral_source'] ?? '', $lang),
        csv_excel_text($r['phone'] ?? ''),
      ];
    }
    self::send_csv(
      'First_Timers_' . date_i18n('Ymd', current_time('timestamp')) . '.csv',
      ['First Name', 'Last Name', 'First Attended Date', 'Referral Source', 'Phone'],
      $lines
    );
  }

  /** 新来宾名单（JSON：rows + html） */
  public static function newcomers_query(): void
  {
    self::require_list_access();
    [$start, $end] = self::request_range();
    $rows = Attendance_DB::query_first_timers_log($start, $end, self::request_scope());
    wp_send_json_success([
      'count'        => count($rows),
      'rows'         => $rows,
      'html'         => Frontend_Page::render_first_timers_list_html($rows, true, true, true, ap_ui_lang(), Access::is_admin()),
      'generated_at' => date_i18n('Y-m-d H:i', current_time('timestamp')),
    ]);
  }

  /** 旧接口名，与 newcomers_query 相同 */
  public static function first_timers_log_query(): void
  {
    self::newcomers_query();
  }

  public static function newcomers_export(): void
  {
    self::require_list_access(false);
    [$start, $end] = self::request_range();
    $lang  = ap_ui_lang();
    $admin = Access::is_admin();
    $rows  = Attendance_DB::query_first_timers_log($start, $end, self::request_scope());

    $head = ['First Name', 'Last Name', 'Phone', 'Referral Source', 'First Attendance Date'];
    if ($admin) $head[] = I18n::t('column_congregation', $lang);
    $lines = [];
    foreach ($rows as $r) {
      $l = [
        $r['first_name'] ?? '',
        $r['last_name'] ?? '',
        csv_excel_text($r['phone'] ?? ''),
        ap_translate_referral_source($r['referral_source'] ?? '', $lang),
        $r['first_attendance_date'] ?? '',
      ];
      if ($admin) $l[] = Congregation::label($r['congregation'], $lang);
      $lines[] = $l;
    }
    self::send_csv('newcomers_' . $start . '_to_' . $end . '.csv', $head, $lines);
  }

  /** 旧接口名，与 newcomers_export 相同 */
  public static function first_timers_log_export(): void
  {
    self::newcomers_export();
  }

  public static function handle_delete_newcomer(): void
  {
    if (!Access::can_access()) {
      wp_send_json_error(['message' => 'Permission denied'], 403);
    }
    $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, 'ap_ft_delete_newcomer')) {
      wp_send_json_error(['message' => 'Bad nonce'], 400);
    }
    $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
    if ($id <= 0) {
      wp_send_json_error(['message' => 'Invalid ID'], 400);
    }
    // 同工只能删自己堂的记录；找不到（或不属于本堂）一律 404
    if (!Attendance_DB::delete_newcomer($id, Access::scope(null))) {
      wp_send_json_error(['message' => 'Record not found'], 404);
    }
    wp_send_json_success(['id' => $id]);
  }
}
