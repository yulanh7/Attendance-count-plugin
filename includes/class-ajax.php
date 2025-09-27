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

  public static function handle_attendance()
  {
    self::verify_nonce();
    $res = Attendance_DB::handle_submit($_POST);
    if ($res['ok']) wp_send_json_success(['message' => $res['msg']]);
    wp_send_json_error(['message' => $res['msg']]);
  }

  public static function filter_attendance()
  {
    self::verify_nonce();
    $rows = \AP\Attendance_DB::query_filtered($_POST);

    $is_frontend = !empty($_POST['fe']) && $_POST['fe'] == '1';

    ob_start();
    if ($is_frontend) {
      list($rows_page, $page, $pages) = \AP\Admin_Page::paginate_array($rows, (int)$_POST['paged'] ?: 1, AP_FRONT_PER_PAGE);
      ob_start();
      \AP\Admin_Page::render_table_simple($rows_page);
      \AP\Admin_Page::render_pagination_simple($page, $pages);
      $html = ob_get_clean();

      wp_send_json_success([
        'table_html' => $html, // 包含表格 + 分页
      ]);
    } else {
      \AP\Admin_Page::render_table($rows);
    }

    echo '<div id="loader-box" style="display:none;"><div id="es-loading-spinner" class="loader"></div></div>';
    $html = ob_get_clean();
    wp_send_json_success(['table_html' => $html]);
  }

  public static function export_csv()
  {
    self::verify_nonce();
    $csv = Attendance_DB::build_csv($_POST);
    header('Content-Type: text/csv; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $csv;
    wp_die();
  }

  public static function update_member()
  {
    self::verify_nonce();
    $msg = Attendance_DB::bulk_update_member($_POST);
    wp_send_json_success(['message' => $msg]);
  }

  public static function get_attendance_info()
  {
    self::verify_nonce();
    $id   = absint($_POST['attendance_id'] ?? 0);
    $start = sanitize_text_field($_POST['start_date_filter'] ?? '');
    $end  = sanitize_text_field($_POST['end_date_filter'] ?? '');
    $data = Attendance_DB::get_detail($id, $start, $end);

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
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data['list'] as $row): ?>
            <tr>
              <td style="border:1px solid #ccc; padding:6px;">
                <?php echo esc_html(\AP\format_date_dmy($row['date_attended'])); ?>
              </td>
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

  public static function first_timers_query()
  {
    if (!is_user_logged_in() || !current_user_can('read')) {
      if (!headers_sent()) {
        nocache_headers();
      }
      wp_send_json_error(['message' => 'Forbidden'], 403);
    }

    check_ajax_referer('es_attendance_nonce', 'nonce');

    if (!headers_sent()) {
      nocache_headers();
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
      header('Pragma: no-cache');
      header('Expires: 0');
      header('Vary: Cookie');
    }

    $nonce_ok = isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'es_attendance_nonce');
    $can_view_phone = ($nonce_ok && is_user_logged_in() && current_user_can('read'));

    $start = isset($_POST['start']) ? sanitize_text_field($_POST['start']) : '';
    $end   = isset($_POST['end'])   ? sanitize_text_field($_POST['end'])   : '';

    $to_date = function ($s) {
      $dt = \DateTime::createFromFormat('Y-m-d', $s);
      return $dt ? $dt->format('Y-m-d') : null;
    };
    $today = current_time('Y-m-d');
    $start = $to_date($start) ?: $today;
    $end   = $to_date($end)   ?: $today;

    $can_view_phone = (is_user_logged_in() && current_user_can('read'));

    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT first_name, last_name, phone, first_attendance_date
           FROM {$attendance}
          WHERE first_attendance_date BETWEEN %s AND %s
          ORDER BY first_attendance_date DESC, last_name, first_name",
        $start,
        $end
      ),
      ARRAY_A
    );

    $html = \AP\Frontend_Page::render_first_timers_list_html($rows, $can_view_phone);

    wp_send_json_success([
      'html' => $html,
      'generated_at' => date_i18n('Y-m-d H:i', current_time('timestamp')),
      'count' => count($rows),
    ]);
  }

  /** AJAX：导出 CSV（Excel 可直接打开） */
  public static function first_timers_export()
  {
    if (!is_user_logged_in() || !current_user_can('read')) {
      if (!headers_sent()) {
        nocache_headers();
        status_header(403);
        header('Content-Type: text/plain; charset=utf-8');
      }
      echo 'Forbidden';
      exit;
    }
    check_ajax_referer('es_attendance_nonce', 'nonce');

    $nonce_ok = isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'es_attendance_nonce');
    $can_view_phone = ($nonce_ok && is_user_logged_in() && current_user_can('read'));

    $start = isset($_POST['start']) ? sanitize_text_field($_POST['start']) : '';
    $end   = isset($_POST['end'])   ? sanitize_text_field($_POST['end'])   : '';

    $to_date = function ($s) {
      $dt = \DateTime::createFromFormat('Y-m-d', $s);
      return $dt ? $dt->format('Y-m-d') : null;
    };
    $today = current_time('Y-m-d');
    $start = $to_date($start) ?: $today;
    $end   = $to_date($end)   ?: $today;

    $can_view_phone = (is_user_logged_in() && current_user_can('read'));

    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT first_name, last_name, phone, first_attendance_date
           FROM {$attendance}
          WHERE first_attendance_date BETWEEN %s AND %s
          ORDER BY first_attendance_date DESC, last_name, first_name",
        $start,
        $end
      ),
      ARRAY_A
    );

    // CSV 头
    $head = ['First Name', 'Last Name', 'First Attended Date'];
    if ($can_view_phone) $head[] = 'Phone';

    // 构建 CSV（带 BOM 以便 Excel 识别 UTF-8）
    $lines = [];
    $lines[] = \AP\csv_escape('Date Range') . ',' . \AP\csv_escape(\AP\format_date_dmy($start) . ' to ' . \AP\format_date_dmy($end));
    $lines[] = '';
    $lines[] = implode(',', array_map('\AP\csv_escape', $head));

    foreach ($rows as $r) {
      $cols = [
        $r['first_name'] ?? '',
        $r['last_name'] ?? '',
        \AP\format_date_dmy($r['first_attendance_date'] ?? ''),
      ];
      if ($can_view_phone) $cols[] = $r['phone'] ?? '';
      $lines[] = implode(',', array_map('\AP\csv_escape', $cols));
    }

    $csv = "\xEF\xBB\xBF" . implode("\n", $lines); // BOM + 内容

    // 输出
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Type: text/csv; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $fn_date = date_i18n('Ymd', current_time('timestamp'));
    header('Content-Disposition: attachment; filename="First_Timers_' . $fn_date . '.csv"');
    echo $csv;
    exit;
  }

  /**
   * 快速签到（仅凭电话号码）
   */
  public static function quick_attendance()
  {
    self::verify_nonce();

    $phone = sanitize_text_field($_POST['phone'] ?? '');
    if (!$phone) {
      wp_send_json_error(['message' => '电话号码不能为空']);
    }

    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';
    $dates = $wpdb->prefix . 'attendance_dates';

    // 检查用户是否存在
    $user = $wpdb->get_row($wpdb->prepare(
      "SELECT id, first_name, last_name FROM $attendance WHERE phone = %s",
      $phone
    ));

    if (!$user) {
      wp_send_json_error(['message' => '该电话号码未注册，请先进行首次登记']);
    }

    // 仅允许目标星期签到
    if ((int) current_time('w') !== get_target_dow()) {
      wp_send_json_error(['message' => '今天不是允许的签到日，不能签到。']);
    }

    $today = current_time('Y-m-d');

    // 写入当日出勤（依赖 dates 唯一键）
    $ins = $wpdb->query($wpdb->prepare(
      "INSERT IGNORE INTO $dates (attendance_id, date_attended) VALUES (%d, %s)",
      $user->id,
      $today
    ));

    if ($ins === false) {
      wp_send_json_error(['message' => '记录签到失败，请稍后再试。']);
    }

    if ($ins === 0) {
      wp_send_json_error(['message' => '您今天已经签到过了，请勿重复签到!']);
    }

    wp_send_json_success(['message' => "签到成功！欢迎 {$user->first_name} {$user->last_name}"]);
  }

  /**
   * 获取用户资料
   */
  public static function get_user_profile()
  {
    self::verify_nonce();

    $phone = sanitize_text_field($_POST['phone'] ?? '');
    if (!$phone) {
      wp_send_json_error(['message' => '电话号码不能为空']);
    }

    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';

    $user = $wpdb->get_row($wpdb->prepare(
      "SELECT first_name, last_name, phone, email, fellowship FROM $attendance WHERE phone = %s",
      $phone
    ));

    if (!$user) {
      wp_send_json_error(['message' => '找不到该用户资料']);
    }

    // 解析电话号码
    $phone_parts = self::parse_phone_number($user->phone);

    wp_send_json_success([
      'first_name' => $user->first_name,
      'last_name' => $user->last_name,
      'email' => $user->email,
      'fellowship' => $user->fellowship,
      'country_code' => $phone_parts['country_code'],
      'phone_number' => $phone_parts['phone_number'],
    ]);
  }

  /**
   * 更新用户资料（需要提供电话号码查找用户）
   */
  public static function update_profile()
  {
    self::verify_nonce();

    $first_name = trim(sanitize_text_field($_POST['es_first_name'] ?? ''));
    $last_name = trim(sanitize_text_field($_POST['es_last_name'] ?? ''));
    $email = sanitize_email($_POST['es_email'] ?? '');
    $fellowship = trim(sanitize_text_field($_POST['es_fellowship'] ?? ''));
    $country_code = trim(sanitize_text_field($_POST['es_phone_country_code'] ?? ''));
    $phone_number = trim(sanitize_text_field($_POST['es_phone_number'] ?? ''));

    // 电话号码归一化处理
    $phone_number = preg_replace('/[\s\-\(\)]/', '', $phone_number);
    if ($country_code === '+61' && substr($phone_number, 0, 1) === '0') {
      $phone_number = substr($phone_number, 1);
    }
    $phone = $country_code . $phone_number;

    if (!$first_name || !$last_name || !$fellowship || !$phone) {
      wp_send_json_error(['message' => '请填写所有必填字段']);
    }

    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';

    // 检查用户是否存在
    $user_id = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $attendance WHERE phone = %s",
      $phone
    ));

    if (!$user_id) {
      wp_send_json_error(['message' => '该电话号码不存在，请先进行首次登记']);
    }

    // 更新资料
    $result = $wpdb->update(
      $attendance,
      [
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'fellowship' => $fellowship,
      ],
      ['id' => $user_id],
      ['%s', '%s', '%s', '%s'],
      ['%d']
    );

    if ($result === false) {
      wp_send_json_error(['message' => '更新资料失败，请稍后再试']);
    }

    wp_send_json_success(['message' => "资料更新成功！用户：{$first_name} {$last_name}"]);
  }

  /**
   * 解析电话号码为国家代码和号码
   */
  private static function parse_phone_number($full_phone)
  {
    // 常见的国家代码
    $country_codes = ['+61', '+86', '+852', '+886', '+60', '+65'];

    foreach ($country_codes as $code) {
      if (strpos($full_phone, $code) === 0) {
        return [
          'country_code' => $code,
          'phone_number' => substr($full_phone, strlen($code))
        ];
      }
    }

    // 默认返回
    return [
      'country_code' => '+61',
      'phone_number' => $full_phone
    ];
  }

  public static function first_timers_log_query(): void
  {
    // 安全 & 权限（与现有 first_timers_query 完全一致）
    check_ajax_referer('es_attendance_nonce', 'nonce');

    if (!is_user_logged_in() || !current_user_can('read')) {
      wp_send_json_error(['message' => __('Permission denied', 'attendance-plugin')], 403);
    }

    $start = isset($_POST['start']) ? sanitize_text_field(wp_unslash($_POST['start'])) : current_time('Y-m-d');
    $end   = isset($_POST['end'])   ? sanitize_text_field(wp_unslash($_POST['end']))   : current_time('Y-m-d');

    // 唯一的区别：直接读日志表
    $rows = \AP\Attendance_DB::query_first_timers_log($start, $end);

    wp_send_json_success([
      'count' => count($rows),
      'rows'  => $rows,
    ]);
  }

  public static function first_timers_log_export(): void
  {
    // 安全 & 权限（与现有 first_timers_export 完全一致）
    check_admin_referer('es_attendance_nonce', 'nonce');

    if (!is_user_logged_in() || !current_user_can('read')) {
      wp_die(__('Permission denied', 'attendance-plugin'));
    }

    $start = isset($_POST['start']) ? sanitize_text_field(wp_unslash($_POST['start'])) : current_time('Y-m-d');
    $end   = isset($_POST['end'])   ? sanitize_text_field(wp_unslash($_POST['end']))   : current_time('Y-m-d');

    // 唯一的区别：直接读日志表
    $rows = \AP\Attendance_DB::query_first_timers_log($start, $end);

    // === 以下与原导出逻辑一致：设置头 & 输出 CSV ===
    if (!headers_sent()) {
      nocache_headers();
      header('Content-Type: text/csv; charset=UTF-8');
      header('Content-Disposition: attachment; filename="first_timers_log_' . sanitize_file_name($start . '_to_' . $end) . '.csv"');
      header('Pragma: no-cache');
    }

    $out = fopen('php://output', 'w');

    // 表头（保持与原来的导出列一致）
    fputcsv($out, ['First Name', 'Last Name', 'Phone', 'First Attendance Date']);

    foreach ($rows as $r) {
      fputcsv($out, [
        $r['first_name'] ?? '',
        $r['last_name'] ?? '',
        $r['phone'] ?? '',
        $r['first_attendance_date'] ?? '',
      ]);
    }

    fclose($out);
    exit;
  }

  /**
   * 新来宾查询 AJAX 接口
   */
  public static function newcomers_query(): void
  {
    // 安全 & 权限（与现有 first_timers_query 完全一致）
    check_ajax_referer('es_attendance_nonce', 'nonce');

    if (!is_user_logged_in() || !current_user_can('read')) {
      wp_send_json_error(['message' => __('Permission denied', 'attendance-plugin')], 403);
    }

    $start = isset($_POST['start']) ? sanitize_text_field(wp_unslash($_POST['start'])) : current_time('Y-m-d');
    $end   = isset($_POST['end'])   ? sanitize_text_field(wp_unslash($_POST['end']))   : current_time('Y-m-d');

    // 直接读日志表
    $rows = \AP\Attendance_DB::query_first_timers_log($start, $end);

    wp_send_json_success([
      'count' => count($rows),
      'rows'  => $rows,
      'html'  => \AP\Frontend_Page::render_first_timers_list_html($rows, true), // 权限用户可以看电话
      'generated_at' => date_i18n('Y-m-d H:i', current_time('timestamp')),
    ]);
  }

  /**
   * 新来宾导出 AJAX 接口
   */
  public static function newcomers_export(): void
  {
    // 安全 & 权限（与现有 first_timers_export 完全一致）
    check_admin_referer('es_attendance_nonce', 'nonce');

    if (!is_user_logged_in() || !current_user_can('read')) {
      wp_die(__('Permission denied', 'attendance-plugin'));
    }

    $start = isset($_POST['start']) ? sanitize_text_field(wp_unslash($_POST['start'])) : current_time('Y-m-d');
    $end   = isset($_POST['end'])   ? sanitize_text_field(wp_unslash($_POST['end']))   : current_time('Y-m-d');

    // 直接读日志表
    $rows = \AP\Attendance_DB::query_first_timers_log($start, $end);

    // === 以下与原导出逻辑一致：设置头 & 输出 CSV ===
    if (!headers_sent()) {
      nocache_headers();
      header('Content-Type: text/csv; charset=UTF-8');
      header('Content-Disposition: attachment; filename="newcomers_' . sanitize_file_name($start . '_to_' . $end) . '.csv"');
      header('Pragma: no-cache');
    }

    $out = fopen('php://output', 'w');

    // 表头（保持与原来的导出列一致）
    fputcsv($out, ['First Name', 'Last Name', 'Phone', 'First Attendance Date']);

    foreach ($rows as $r) {
      fputcsv($out, [
        $r['first_name'] ?? '',
        $r['last_name'] ?? '',
        $r['phone'] ?? '',
        $r['first_attendance_date'] ?? '',
      ]);
    }

    fclose($out);
    exit;
  }

  public static function handle_delete_newcomer(): void
  {
    // 权限 & Nonce
    if (! is_user_logged_in()) {
      wp_send_json_error(['message' => __('Not logged in', 'attendance-plugin')], 401);
    }
    // 你可以按需提升权限门槛，例如 current_user_can('edit_posts') 或 manage_options
    if (! current_user_can('read')) {
      wp_send_json_error(['message' => __('Permission denied', 'attendance-plugin')], 403);
    }
    $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
    if (! wp_verify_nonce($nonce, 'ap_ft_delete_newcomer')) {
      wp_send_json_error(['message' => __('Bad nonce', 'attendance-plugin')], 400);
    }

    // 参数
    $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
    if ($id <= 0) {
      wp_send_json_error(['message' => __('Invalid ID', 'attendance-plugin')], 400);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'attendance_first_time_attendance_dates';

    // 只删除 newcomers 表
    $deleted = $wpdb->delete($table, ['id' => $id], ['%d']);

    if ($deleted === false) {
      // SQL 错误
      wp_send_json_error(['message' => __('DB error when deleting', 'attendance-plugin')], 500);
    } elseif ($deleted === 0) {
      // 没有匹配行
      wp_send_json_error(['message' => __('Record not found', 'attendance-plugin')], 404);
    }

    // 成功
    wp_send_json_success(['id' => $id]);
  }
}
