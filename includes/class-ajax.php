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
    $rows = Attendance_DB::query_filtered($_POST);
    ob_start();
    Admin_Page::render_table($rows);
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
    <div><?php echo esc_html(($data['user']->first_name ?? '') . ' ' . ($data['user']->last_name ?? '')); ?></div>
    <div><?php echo esc_html($data['user']->phone ?? ''); ?></div>
    <p><?php echo esc_html(\AP\format_date_dmy($start)); ?> to <?php echo esc_html(\AP\format_date_dmy($end)); ?></p>
    <table>
      <thead>
        <tr>
          <th>Date Attended</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($data['list'] as $row): ?>
          <tr>
            <td><?php echo esc_html(\AP\format_date_dmy($row['date_attended'])); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
<?php
    echo ob_get_clean();
    wp_die();
  }
}
