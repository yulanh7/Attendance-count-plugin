<?php

/**
 * Plugin Name: Attendance Plugin
 * Description: A WordPress plugin to manage attendance.
 * Version: 1.92
 * Author: Rachel Huang
 */


defined('ABSPATH') or die('No script kiddies please!');

if (!defined('ES_ATTENDANCE_DOW')) {
  define('ES_ATTENDANCE_DOW', 3);
}
/**
 * ====== 统一签到星期配置 ======
 * 0=周日, 1=周一, ... 6=周六
 * 测试时可在 wp-config.php 定义：define('ES_ATTENDANCE_DOW', 3); // 周三
 */
if (!function_exists('es_get_target_dow')) {
  function es_get_target_dow()
  {
    if (defined('ES_ATTENDANCE_DOW')) {
      return ((int) ES_ATTENDANCE_DOW) % 7;
    }
    $opt = get_option('es_attendance_dow', 0); // 默认周日
    return is_numeric($opt) ? ((int) $opt % 7) : 0;
  }
}

/**
 * Activation: create tables
 */
function create_attendance_table()
{
  global $wpdb;
  $attendance_table_name = $wpdb->prefix . 'attendance';
  $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates';
  $charset_collate = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $attendance_table_name (
      id INT NOT NULL AUTO_INCREMENT,
      first_name VARCHAR(255) NOT NULL,
      last_name VARCHAR(255) NOT NULL,
      phone VARCHAR(20) NOT NULL,
      email VARCHAR(255),
      fellowship VARCHAR(255) NOT NULL,
      is_new BOOLEAN DEFAULT 1,
      is_member BOOLEAN DEFAULT 0,
      first_attendance_date DATE NOT NULL,
      PRIMARY KEY (id)
  ) $charset_collate;";

  $sql .= "CREATE TABLE $attendance_dates_table_name (
      id INT NOT NULL AUTO_INCREMENT,
      attendance_id INT NOT NULL,
      date_attended DATE NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY unique_attendance_date (attendance_id, date_attended),
      FOREIGN KEY (attendance_id) REFERENCES $attendance_table_name(id)
  ) $charset_collate;";

  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
}
register_activation_hook(__FILE__, 'create_attendance_table');

/**
 * Enqueue scripts/styles & inject AJAX settings
 */
function es_enqueue_scripts()
{
  wp_enqueue_script('jquery');
  wp_enqueue_script('es-attendance', plugin_dir_url(__FILE__) . 'main.js', ['jquery'], '1.0', true);

  // Inject ajaxurl + nonce to JS
  wp_localize_script('es-attendance', 'esAjax', [
    'ajaxurl' => admin_url('admin-ajax.php'),
    'nonce'   => wp_create_nonce('es_attendance_nonce'),
  ]);

  wp_enqueue_style('custom-style', plugin_dir_url(__FILE__) . 'style.css');
  wp_enqueue_script('jquery-ui-datepicker');
  wp_enqueue_style('jquery-ui-datepicker-style', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
}
add_action('wp_enqueue_scripts', 'es_enqueue_scripts');
add_action('admin_enqueue_scripts', 'es_enqueue_scripts');

/**
 * Shortcode: attendance form
 */
function attendance_form()
{
  ob_start();

  $targetDow  = es_get_target_dow();
  $todayDow   = (int) current_time('w');
  $isAllowed  = ($todayDow === $targetDow);
  $todayDate  = esc_html(current_time('d/m/Y'));

  $weekdayMap = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  $targetLabel = $weekdayMap[$targetDow];

  $dateMessage = $isAllowed
    ? "Date: {$todayDate} ({$targetLabel})"
    : "<span style='color:#ef2723;font-size:18px'>今天不是 {$targetLabel}，不能签到。</span>";
?>
  <form id="es_attendance_form" class="es-attendance-form">
    <input type="text" name="es_first_name" required placeholder="名字（必填）">
    <input type="text" name="es_last_name" required placeholder="姓氏（必填）">
    <input type="email" name="es_email" placeholder="邮箱（选填）">
    <div class="phone-box">
      <select name="es_phone_country_code" required>
        <option value="+61" selected>+61</option>
        <option value="+86">+86</option>
        <option value="+852">+852</option>
        <option value="+886">+886</option>
        <option value="+60">+60</option>
        <option value="+65">+65</option>
      </select>
      <input type="text" name="es_phone_number" required placeholder="电话号码（必填）">
    </div>
    <select name="es_fellowship" required>
      <option value="" disabled selected>您的团契（必选）</option>
      <option value="daniel">但以理团契</option>
      <option value="trueLove">真爱团团契</option>
      <option value="faithHopeLove">信望爱团契</option>
      <option value="peaceJoyPrayer">平安喜乐祷告会</option>
      <option value="other">其他</option>
    </select>
    <div id="date-message"><?php echo $dateMessage; ?></div>
    <input type="submit" name="submit_attendance" value="Submit Attendance" <?php echo $isAllowed ? '' : 'disabled'; ?>>
  </form>
<?php
  return ob_get_clean();
}
add_shortcode('attendance_form', 'attendance_form');

/**
 * 通用：统计某个星期几在区间内的次数
 */
function es_calculate_targetday_count($start_date, $end_date, $targetDow)
{
  $start = new DateTime($start_date);
  $end   = new DateTime($end_date);
  $interval = new DateInterval('P1D');
  $count = 0;
  while ($start <= $end) {
    if ((int)$start->format('w') === (int)$targetDow) {
      $count++;
    }
    $start->add($interval);
  }
  return $count;
}

/**
 * AJAX: handle attendance submit (public)
 */
function es_handle_attendance()
{
  check_ajax_referer('es_attendance_nonce', 'nonce');

  // 根据统一配置限制签到日
  $targetDow = es_get_target_dow();
  if ((int) current_time('w') !== $targetDow) {
    wp_send_json_error(['message' => '今天不是允许的签到日，不能签到。']);
    return;
  }

  $first_name   = sanitize_text_field($_POST['es_first_name'] ?? '');
  $last_name    = sanitize_text_field($_POST['es_last_name'] ?? '');
  $country_code = sanitize_text_field($_POST['es_phone_country_code'] ?? '');
  $phone_number = sanitize_text_field($_POST['es_phone_number'] ?? '');
  $fellowship   = sanitize_text_field($_POST['es_fellowship'] ?? '');
  $email        = sanitize_email($_POST['es_email'] ?? '');
  $current_date = current_time('Y-m-d');

  if ($country_code === '+61' && substr($phone_number, 0, 1) === '0') {
    $phone_number = substr($phone_number, 1);
  }
  $phone = $country_code . $phone_number;

  global $wpdb;
  $attendance_table_name       = $wpdb->prefix . 'attendance';
  $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates';

  // Find existing user by phone
  $existing_user = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM $attendance_table_name WHERE phone = %s", $phone),
    ARRAY_A
  );

  // Check duplicate for today
  $existing_entry = 0;
  if ($existing_user) {
    $existing_entry = (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(*) FROM $attendance_dates_table_name WHERE attendance_id = %d AND date_attended = %s",
        $existing_user['id'],
        $current_date
      )
    );
  }

  if ($existing_entry > 0) {
    wp_send_json_error(['message' => '您今天已经签到过了，请勿重复签到!']);
    return;
  }

  // Insert or update attendee
  if ($existing_user) {
    $wpdb->update(
      $attendance_table_name,
      [
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'fellowship' => $fellowship,
        'email'      => $email,
        'is_new'     => 0,
      ],
      ['phone' => $phone],
      ['%s', '%s', '%s', '%s', '%d'],
      ['%s']
    );
    $attendance_id = (int) $existing_user['id'];
  } else {
    $res = $wpdb->insert(
      $attendance_table_name,
      [
        'first_name'            => $first_name,
        'last_name'             => $last_name,
        'fellowship'            => $fellowship,
        'phone'                 => $phone,
        'email'                 => $email,
        'is_new'                => 1,
        'first_attendance_date' => $current_date,
      ],
      ['%s', '%s', '%s', '%s', '%s', '%d', '%s']
    );
    if ($res === false) {
      wp_send_json_error(['message' => '创建新用户失败，请稍后再试。']);
      return;
    }
    $attendance_id = (int) $wpdb->insert_id;
  }

  // Insert attendance date
  $ins = $wpdb->insert(
    $attendance_dates_table_name,
    [
      'attendance_id' => $attendance_id,
      'date_attended' => $current_date,
    ],
    ['%d', '%s']
  );

  if ($ins === false) {
    wp_send_json_error(['message' => '记录签到失败，请稍后再试。']);
    return;
  }

  wp_send_json_success(['message' => '签到成功！']);
}
add_action('wp_ajax_es_handle_attendance', 'es_handle_attendance');
add_action('wp_ajax_nopriv_es_handle_attendance', 'es_handle_attendance');

/**
 * Admin table class
 */
if (!class_exists('WP_List_Table')) {
  require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class ES_Attendance_List extends WP_List_Table
{
  public $per_page = 40;

  function prepare_items($data = array())
  {
    $columns = $this->get_columns();
    $hidden = array();
    $sortable = $this->get_sortable_columns();
    $this->_column_headers = array($columns, $hidden, $sortable);
    $current_page = $this->get_pagenum();
    $total_items = count($data);

    $this->set_pagination_args(array(
      'total_items' => $total_items,
      'per_page'    => $this->per_page,
      'total_pages' => ceil($total_items / $this->per_page)
    ));
    $this->items = array_slice($data, (($current_page - 1) * $this->per_page), $this->per_page);
  }

  function get_columns()
  {
    return [
      'cb'                     => '<input type="checkbox" />',
      'row_num'                => 'No.',
      'fellowship'             => 'Fellowships',
      'first_name'             => 'First Name',
      'last_name'              => 'Last Name',
      'phone'                  => 'Phone',
      'email'                  => 'Email',
      'times'                  => 'Times',
      'percentage'             => 'Percentage',
      'first_attendance_date'  => 'First attended Date',
      'last_attended'          => 'Last Attended Date',
      'is_member'              => 'Member',
      'view_attendance'        => 'View Attendance',
    ];
  }

  function column_cb($item)
  {
    return sprintf(
      '<input type="checkbox" name="bulk-select[]" value="%s" />',
      esc_attr($item['id'])
    );
  }

  function get_bulk_actions()
  {
    return [
      'make_member'    => 'Make Member',
      'make_non_member' => 'Make Non-Member',
    ];
  }

  function column_default($item, $column_name)
  {
    switch ($column_name) {
      case 'row_num':
      case 'first_name':
      case 'last_name':
      case 'email':
      case 'phone':
      case 'times':
      case 'last_attended':
        return esc_html($item[$column_name]);

      case 'view_attendance':
        return '<button class="view-attendance-button" data-attendance-id="' . esc_attr($item['id']) . '">View</button>';

      case 'fellowship':
        switch ($item[$column_name]) {
          case 'daniel':
            return '但以理团契';
          case 'trueLove':
            return '真爱团团契';
          case 'faithHopeLove':
            return '信望爱团契';
          case 'peaceJoyPrayer':
            return '平安喜乐祷告会';
          case 'other':
            return '其他';
          default:
            return '';
        }

      case 'is_member':
        return !empty($item[$column_name]) ? 'Yes' : 'No';

      case 'first_attendance_date':
        return esc_html(date('d/m/Y', strtotime($item[$column_name])));

      case 'percentage':
        return esc_html($item[$column_name] . "%");

      default:
        return esc_html(print_r($item, true));
    }
  }
}

/**
 * Helpers
 */
function get_last_attended_date($phone)
{
  global $wpdb;
  $attendance_table_name       = $wpdb->prefix . 'attendance';
  $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates';

  $query = $wpdb->prepare(
    "SELECT MAX(D.date_attended)
     FROM $attendance_table_name AS A
     INNER JOIN $attendance_dates_table_name AS D ON A.id = D.attendance_id
     WHERE A.phone = %s",
    $phone
  );
  return $wpdb->get_var($query);
}

function combine_attendace_with_same_phone($data, $start_date, $end_date, $percentage_filter = false)
{
  $combinedData = [];
  $targetDow = es_get_target_dow();

  foreach ($data as $entry) {
    $phone = $entry['phone'];

    $new_start_date    = new DateTime($entry['first_attendance_date']);
    $format_start_date = new DateTime($start_date);
    $adjusted_start_date = ($format_start_date < $new_start_date) ? $new_start_date : $format_start_date;

    $target_count = es_calculate_targetday_count($adjusted_start_date->format('Y-m-d'), $end_date, $targetDow);

    if (!isset($combinedData[$phone])) {
      // first hit
      $combinedData[$phone] = $entry;
      $combinedData[$phone]['times'] = 1;
      $combinedData[$phone]['percentage'] = ($target_count <= 0) ? 0 : number_format(1 / $target_count * 100, 2, '.', '');
      $last_attended_date = get_last_attended_date($phone);
      $combinedData[$phone]['last_attended'] = $last_attended_date ? date('d/m/Y', strtotime($last_attended_date)) : '';
    } else {
      // subsequent hit
      $combinedData[$phone]['times']++;
      $combinedData[$phone]['percentage'] = ($target_count <= 0)
        ? 0
        : number_format($combinedData[$phone]['times'] / $target_count * 100, 2, '.', '');

      // Update to latest fields by bigger ID
      if ($entry['id'] > $combinedData[$phone]['id']) {
        $combinedData[$phone]['first_name'] = $entry['first_name'];
        $combinedData[$phone]['last_name']  = $entry['last_name'];
        $combinedData[$phone]['phone']      = $entry['phone'];
        $combinedData[$phone]['fellowship'] = $entry['fellowship'];
        $combinedData[$phone]['is_new']     = $entry['is_new'];
      }
      $last_attended_date = get_last_attended_date($phone);
      $combinedData[$phone]['last_attended'] = $last_attended_date ? date('d/m/Y', strtotime($last_attended_date)) : '';
    }
  }

  $combinedData = array_values($combinedData);

  if ($percentage_filter) {
    $combinedData = array_filter($combinedData, function ($item) {
      return is_numeric($item['percentage']) && $item['percentage'] >= 50;
    });
  }

  foreach ($combinedData as $key => $value) {
    $combinedData[$key] = ['row_num' => $key + 1] + $value;
  }
  return $combinedData;
}

/**
 * Admin screen render
 */
function es_render_attendance_list()
{
  global $wpdb;
  $attendance_table_name       = $wpdb->prefix . 'attendance';
  $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates';
  $start_date = current_time('Y-m-d');
  $end_date   = current_time('Y-m-d');

  $query = "SELECT A.*
            FROM $attendance_table_name AS A
            INNER JOIN $attendance_dates_table_name AS D ON A.id = D.attendance_id 
            WHERE 1=1";
  $query .= $wpdb->prepare(" AND D.date_attended >= %s", $start_date);
  $query .= $wpdb->prepare(" AND D.date_attended <= %s", $end_date);

  $results = $wpdb->get_results($query, ARRAY_A);
  foreach ($results as &$item) {
    $item['start_date'] = $start_date;
    $item['end_date']   = $end_date;
  }
  $results = combine_attendace_with_same_phone($results, $start_date, $end_date, false);
  $attendanceListTable = new ES_Attendance_List();
  $attendanceListTable->prepare_items($results);
?>
  <div class="wrap">
    <h2>Attendance</h2>
    <div class="filter-form">
      <select name="es_fellowship_filter" id="es_fellowship_filter">
        <option value="" selected>全部团契</option>
        <option value="Daniel">但以理团契</option>
        <option value="True love">真爱团团契</option>
        <option value="Faith Hope Love">信望爱团契</option>
        <option value="Peace&Joy Prayer">平安喜乐祷告会</option>
        <option value="other">其他</option>
      </select>
      <select name="es_member_filter" id="es_member_filter">
        <option value="" selected>全部会友</option>
        <option value="isMember">会員</option>
        <option value="isNonMember">非会員</option>
      </select>
      <input type="text" id="last_name_filter" placeholder="Last Name">
      <input type="text" id="first_name_filter" placeholder="First Name">
      <input type="text" id="phone_filter" placeholder="phone">
      <input type="text" id="email_filter" placeholder="Email">
      <span style="display:inline-block;">
        <input type="date" id="start_date_filter" placeholder="Start Date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>">
        --
        <input type="date" id="end_date_filter" placeholder="End Date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>">
      </span>
      <div>
        <span class="checkbox-container">
          <input type="checkbox" id="is_new_filter" name="is_new_filter">
          <label for="is_new_filter">New Attendance</label>
        </span>
        <span class="checkbox-container">
          <input type="checkbox" id="percentage_filter" name="percentage_filter">
          <label for="percentage_filter">&gt;= 50%</label>
        </span>
        <button id="filter-button" type="button" class="submit-btn">Filter</button>
        <button id="export-csv-button" type="button" class="export-csv">Export to CSV</button>
      </div>
      <div id="filter-table-response">
        <?php $attendanceListTable->display(); ?>
        <div id="loader-box" style="display:none;">
          <div id="es-loading-spinner" class="loader"></div>
        </div>
      </div>
    </div>

    <div id="attendance-info-modal" class="popup">
      <div class="popup-content">
        <span class="close">&times;</span>
        <div id="attendance-info-modal-content"></div>
      </div>
    </div>
  </div>
<?php
}

/**
 * Filter helpers
 */
function get_filtered_attendance_results($query)
{
  global $wpdb;

  $fellowship   = sanitize_text_field($_POST['fellowship'] ?? '');
  $last_name    = sanitize_text_field($_POST['last_name'] ?? '');
  $first_name   = sanitize_text_field($_POST['first_name'] ?? '');
  $email        = sanitize_text_field($_POST['email'] ?? '');
  $phone        = sanitize_text_field($_POST['phone'] ?? '');
  $is_member    = sanitize_text_field($_POST['is_member'] ?? '');
  $start_date   = sanitize_text_field($_POST['start_date_filter'] ?? '');
  $end_date     = sanitize_text_field($_POST['end_date_filter'] ?? '');
  $percentage_filter = (isset($_POST['percentage_filter']) && $_POST['percentage_filter'] == 'true') ? 1 : 0;

  if (!empty($fellowship)) {
    $query .= $wpdb->prepare(" AND fellowship = %s", $fellowship);
  }
  if (!empty($first_name)) {
    $like_first_name = '%' . $wpdb->esc_like($first_name) . '%';
    $query .= $wpdb->prepare(" AND first_name LIKE %s", $like_first_name);
  }
  if (!empty($last_name)) {
    $like_last_name = '%' . $wpdb->esc_like($last_name) . '%';
    $query .= $wpdb->prepare(" AND last_name LIKE %s", $like_last_name);
  }
  if (!empty($phone)) {
    $like_phone = '%' . $wpdb->esc_like($phone) . '%';
    $query .= $wpdb->prepare(" AND phone LIKE %s", $like_phone);
  }
  if (!empty($email)) {
    $like_email = '%' . $wpdb->esc_like($email) . '%';
    $query .= $wpdb->prepare(" AND email LIKE %s", $like_email);
  }
  if (!empty($is_member)) {
    $is_member_val = ($is_member === "isMember") ? 1 : 0;
    $query .= $wpdb->prepare(" AND is_member = %d", $is_member_val);
  }
  if (isset($_POST['is_new']) && $_POST['is_new'] == 'true') {
    $query .= $wpdb->prepare(" AND is_new = %d", 1);
  }
  if (!empty($start_date)) {
    $start_date_sql = date('Y-m-d', strtotime($start_date));
    $query .= $wpdb->prepare(" AND D.date_attended >= %s", $start_date_sql);
  }
  if (!empty($end_date)) {
    $end_date_sql = date('Y-m-d', strtotime($end_date));
    $query .= $wpdb->prepare(" AND D.date_attended <= %s", $end_date_sql);
  }

  $results = $wpdb->get_results($query, ARRAY_A);

  // 统一使用目标星期几作为分母
  $targetDow = es_get_target_dow();
  $results = combine_attendace_with_same_phone($results, $start_date_sql ?? $start_date, $end_date_sql ?? $end_date, $percentage_filter);
  return $results;
}

/**
 * AJAX: filter list (admin)
 */
function es_filter_attendance_callback()
{
  check_ajax_referer('es_attendance_nonce', 'nonce');

  global $wpdb;
  $attendance_table_name       = $wpdb->prefix . 'attendance';
  $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates';

  $query = "SELECT A.*
            FROM $attendance_table_name AS A
            INNER JOIN $attendance_dates_table_name AS D ON A.id = D.attendance_id 
            WHERE 1=1";

  $results = get_filtered_attendance_results($query);
  $attendanceListTable = new ES_Attendance_List();
  $attendanceListTable->prepare_items($results);

  ob_start();
  $attendanceListTable->display();
  echo '<div id="loader-box" style="display: none;"><div id="es-loading-spinner" class="loader"></div></div>';
  $table_html = ob_get_clean();

  wp_send_json_success(['table_html' => $table_html]);
}
add_action('wp_ajax_es_filter_attendance', 'es_filter_attendance_callback');

/**
 * Admin menu
 * （保留原本权限：read；安全起见建议后续换成 manage_options）
 */
add_action('admin_menu', function () {
  add_menu_page('Attendance', 'Attendance', 'read', 'es-attendance', 'es_render_attendance_list', 'dashicons-calendar', 1);
});

/**
 * CSV export helpers
 */
function es_csv_escape($value)
{
  $v = (string) $value;
  // 防 CSV 注入
  if (preg_match('/^[-+=@].*/', $v)) {
    $v = "'" . $v;
  }
  // 包双引号，并转义内部双引号
  $v = str_replace('"', '""', $v);
  return '"' . $v . '"';
}

/**
 * AJAX: export CSV
 */
function es_export_attendance_csv()
{
  check_ajax_referer('es_attendance_nonce', 'nonce');

  global $wpdb;
  $attendance_table_name       = $wpdb->prefix . 'attendance';
  $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates';

  $query = "SELECT A.fellowship, A.first_name, A.last_name, A.phone, A.email, A.is_member, A.first_attendance_date
            FROM $attendance_table_name AS A
            INNER JOIN $attendance_dates_table_name AS D ON A.id = D.attendance_id 
            WHERE 1=1";

  $results = get_filtered_attendance_results($query);
  $reorderedResults = [];

  foreach ($results as $row) {
    switch ($row['fellowship']) {
      case 'daniel':
        $fellowshipText = 'Daniel';
        break;
      case 'trueLove':
        $fellowshipText = 'True Love';
        break;
      case 'faithHopeLove':
        $fellowshipText = 'Faith Hope Love';
        break;
      case 'peaceJoyPrayer':
        $fellowshipText = 'Peace&Joy Prayer';
        break;
      case 'other':
        $fellowshipText = 'Other';
        break;
      default:
        $fellowshipText = '';
    }
    $reorderedResults[] = [
      $row['row_num'],
      $fellowshipText,
      $row['first_name'],
      $row['last_name'],
      $row['phone'],
      $row['email'],
      $row['times'],
      $row['percentage'] . '%',
      $row['first_attendance_date'],
      $row['last_attended'],
      ($row['is_member'] == '1' ? 'Yes' : 'No'),
    ];
  }

  $header = [
    'No.',
    'Fellowships',
    'First Name',
    'Last Name',
    'Phone',
    'Email',
    'Times',
    'Percentage',
    'First attended Date',
    'Last Attended Date',
    'Member',
  ];

  $lines = [implode(',', array_map('es_csv_escape', $header))];
  foreach ($reorderedResults as $row) {
    $lines[] = implode(',', array_map('es_csv_escape', $row));
  }
  $csv_string = implode("\n", $lines);

  header('Content-Type: text/csv; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  echo $csv_string;
  wp_die();
}
add_action('wp_ajax_es_export_attendance_csv', 'es_export_attendance_csv');

/**
 * AJAX: bulk member status update
 * （此处按你的要求暂不加权限控制）
 */
function handle_member_status_update()
{
  check_ajax_referer('es_attendance_nonce', 'nonce');

  global $wpdb;
  $ids    = isset($_POST['ids']) ? (array) $_POST['ids'] : [];
  $action = isset($_POST['member_action']) ? sanitize_text_field($_POST['member_action']) : '';

  $is_member = ($action === 'make_member') ? 1 : 0;

  foreach ($ids as $id) {
    $wpdb->update(
      $wpdb->prefix . 'attendance',
      ['is_member' => $is_member],
      ['id' => intval($id)],
      ['%d'],
      ['%d']
    );
  }

  wp_send_json_success(['message' => 'Member status updated successfully.']);
}
add_action('wp_ajax_handle_member_status_update', 'handle_member_status_update');

/**
 * AJAX: get attendance info (detail modal)
 */
function get_attendance_info_callback()
{
  check_ajax_referer('es_attendance_nonce', 'nonce');

  global $wpdb;
  $attendance_table_name       = $wpdb->prefix . 'attendance';
  $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates';

  $attendanceId = absint($_POST['attendance_id'] ?? 0);
  $start_date   = sanitize_text_field($_POST['start_date_filter'] ?? '');
  $end_date     = sanitize_text_field($_POST['end_date_filter'] ?? '');

  $attendance = $wpdb->get_row(
    $wpdb->prepare("SELECT first_name, last_name, phone FROM $attendance_table_name WHERE id = %d", $attendanceId)
  );

  $attendanceInfo = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT * FROM $attendance_dates_table_name WHERE attendance_id = %d AND date_attended BETWEEN %s AND %s",
      $attendanceId,
      $start_date,
      $end_date
    ),
    ARRAY_A
  );

  ob_start(); ?>
  <div><?php echo esc_html(($attendance->first_name ?? '') . ' ' . ($attendance->last_name ?? '')); ?></div>
  <div><?php echo esc_html($attendance->phone ?? ''); ?></div>
  <p><?php echo esc_html($start_date); ?> 至 <?php echo esc_html($end_date); ?></p>
  <table>
    <thead>
      <tr>
        <th>Date Attended</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($attendanceInfo as $info): ?>
        <tr>
          <td><?php echo esc_html($info['date_attended']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php
  $attendanceInfoHTML = ob_get_clean();
  echo $attendanceInfoHTML;
  wp_die();
}
add_action('wp_ajax_get_attendance_info', 'get_attendance_info_callback');

/**
 * Deactivation hook
 */
function es_on_deactivation()
{
  global $wpdb;
  if (!current_user_can('activate_plugins')) return;

  $attendance_table_name = $wpdb->prefix . 'attendance';
  $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates';

  // Drop the tables
  $result1 = $wpdb->query("DROP TABLE IF EXISTS $attendance_dates_table_name");
  $result2 = $wpdb->query("DROP TABLE IF EXISTS $attendance_table_name");

  if ($result1 === false || $result2 === false) {
    // Log an error if dropping tables fails
    error_log("Error dropping tables: " . $wpdb->last_error);
  } else {
    // Log success message if tables are dropped successfully
    error_log("Tables dropped successfully.");
  }
}
register_deactivation_hook(__FILE__, 'es_on_deactivation');
