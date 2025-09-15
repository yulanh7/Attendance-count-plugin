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

  public static function first_timers_query()

  {
    if (!headers_sent()) {
      nocache_headers();
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
      header('Pragma: no-cache');
      header('Expires: 0');
    }

    check_ajax_referer('es_attendance_nonce', 'nonce');

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
    ]);
  }

  /** AJAX：导出 CSV（Excel 可直接打开） */
  public static function first_timers_export()
  {
    check_ajax_referer('es_attendance_nonce', 'nonce');

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
    $fn_date = date_i18n('Ymd', current_time('timestamp'));
    header('Content-Disposition: attachment; filename="First_Timers_' . $fn_date . '.csv"');
    echo $csv;
    exit;
  }
}
