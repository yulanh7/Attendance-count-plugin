<?php

/**
 * Plugin Name: Attendance Plugin
 * Description: A WordPress plugin to manage attendance.
 * Version: 1.46
 * Author: Rachel Huang
 */


// require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

defined('ABSPATH') or die('No script kiddies please!');

function create_attendance_table()
{
  global $wpdb;
  $attendance_table_name = $wpdb->prefix . 'attendance';
  $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates'; // New table for attendance dates
  $charset_collate = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $attendance_table_name (
      id INT NOT NULL AUTO_INCREMENT,
      first_name VARCHAR(255) NOT NULL,
      last_name VARCHAR(255) NOT NULL,
      phone VARCHAR(20),
      email VARCHAR(255) NOT NULL,
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
      FOREIGN KEY (attendance_id) REFERENCES $attendance_table_name(id)
  ) $charset_collate;";

  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
}


register_activation_hook(__FILE__, 'create_attendance_table');


function es_enqueue_scripts()
{
  wp_enqueue_script('jquery');
  wp_enqueue_script('es-attendance', plugin_dir_url(__FILE__) . 'main.js', ['jquery'], '1.0', true);
  wp_localize_script('es-attendance', 'esAjax', ['ajaxurl' => admin_url('admin-ajax.php')]);
  wp_enqueue_style('custom-style', plugin_dir_url(__FILE__) . 'style.css');
  wp_enqueue_script('jquery-ui-datepicker');
  wp_enqueue_style('jquery-ui-datepicker-style', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
  wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js');
}

add_action('wp_enqueue_scripts', 'es_enqueue_scripts');
add_action('admin_enqueue_scripts', 'es_enqueue_scripts');

// Function to verify reCAPTCHA
function verify_recaptcha($response)
{
  $secretKey = 'YOUR_RECAPTCHA_SECRET_KEY'; // Replace with your Secret Key
  $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
  $data = array(
    'secret' => $secretKey,
    'response' => $response,
  );

  $options = array(
    'http' => array(
      'header' => 'Content-type: application/x-www-form-urlencoded',
      'method' => 'POST',
      'content' => http_build_query($data),
    ),
  );

  $context = stream_context_create($options);
  $verify = file_get_contents($verifyUrl, false, $context);
  $captchaSuccess = json_decode($verify);

  return $captchaSuccess->success;
}
function attendance_form()
{
  ob_start();
  $currentDayOfWeek = current_time('w');
  // FIXME
  $isSunday = ($currentDayOfWeek == 0);
  // $isSunday = true;
  $todayDate = current_time('d/m/Y');
  $dateMessage = $isSunday ? "Date: $todayDate" : "<span style='color: #ef2723;font-size: 18px'>Today is not a Sunday worship day. You cannot submit attendance today.</span>";

?>

<form id="es_attendance_form" class="es-attendance-form">
  <input type="text" name="es_first_name" required placeholder="First Name *">
  <input type="text" name="es_last_name" required placeholder="Last Name *">
  <input type="email" name="es_email" required placeholder="Email *">
  <input type="text" name="es_phone" placeholder="Phone">
  <select name="es_fellowship">
    <option value="Daniel" selected>但以理团契</option>
    <option value="True love">真爱团团契</option>
    <option value="Faith Hope Love">信望爱团契</option>
    <option value="Peace&Joy Prayer">平安喜乐祷告会</option>
    <option value="other">其他</option>
  </select>
  <div class="g-recaptcha" data-sitekey="6LceSgspAAAAABEtw-MN8TlWYiKDKp7VumOYM06n"></div>
  <!-- For test website -->
  <!-- <div class="g-recaptcha" data-sitekey="6Lcpl_soAAAAABWk5dR0MVbuWMaTaucZyPVA1ApX"></div> -->
  <div id="date-message"><?php echo $dateMessage; ?></div>
  <!-- <div id="date-message"><?php echo date('d/m/Y'); ?></div> -->
  <input type="submit" name="submit_attendance" value="Submit Attendance" <?php echo $isSunday ? '' : 'disabled'; ?>>
</form>
<?php
  return ob_get_clean();
}
add_shortcode('attendance_form', 'attendance_form');


function es_handle_attendance()
{
  $first_name = sanitize_text_field($_POST['es_first_name']);
  $last_name = sanitize_text_field($_POST['es_last_name']);
  $phone = sanitize_text_field($_POST['es_phone']);
  $fellowship = sanitize_text_field($_POST['es_fellowship']);
  $email = sanitize_email($_POST['es_email']);
  $current_date = current_time('Y-m-d');
  // $yesterday_date_only = date('Y-m-d', strtotime($current_time . ' -1 day')); // This will give you just the date part for yesterday

  // Check for duplicate entries on the same date
  global $wpdb;
  $attendance_table_name = $wpdb->prefix . 'attendance';
  $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates';

  // Check if the user already exists in the database
  $existing_user = $wpdb->get_row(
    $wpdb->prepare(
      "SELECT * FROM $attendance_table_name WHERE email = %s",
      $email
    ),
    ARRAY_A
  );

  // Prepare your SQL query using the current date
  $existing_entry = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT * FROM $attendance_dates_table_name WHERE attendance_id = (
      SELECT id FROM $attendance_table_name WHERE email = %s ORDER BY email DESC LIMIT 1
    ) AND date_attended = %s",
      $email,
      $current_date
    ),
    ARRAY_A
  );

  if ($existing_entry) {
    wp_send_json_error(['message' => 'You have already submitted attendance for this person on the same date.']);
    return;
  }


  $attendance_id = '';
  if ($existing_user) {
    $wpdb->update(
      $attendance_table_name,
      ['is_new' => 0],
      ['email' => $email]
    );

    $attendance_id = $existing_user['id'];
  } else {
    $data = array(
      'first_name' => $first_name,
      'last_name' => $last_name,
      'fellowship' => $fellowship,
      'phone' => $phone,
      'email' => $email,
      'is_new' => 1,
      'first_attendance_date' => $current_date,
      // 'first_attendance_date' => date("2024-2-4"),
    );

    $wpdb->insert($attendance_table_name, $data);
    $attendance_id = $wpdb->insert_id;
  }

  // Insert into the attendance_dates table
  $data = array(
    'attendance_id' => $attendance_id,
    'date_attended' => $current_date,
    // 'date_attended' => date("2024-2-4"),
  );

  $wpdb->insert($attendance_dates_table_name, $data);

  wp_send_json_success(['message' => 'Submit successfully!']);
}



add_action('wp_ajax_es_handle_attendance', 'es_handle_attendance');



if (!class_exists('WP_List_Table')) {
  require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class ES_Attendance_List extends WP_List_Table
{
  public $per_page = 10;

  function prepare_items($data = array())
  {
    $columns = $this->get_columns();
    $hidden = array();
    $sortable = $this->get_sortable_columns();
    $this->_column_headers = array($columns, $hidden, $sortable);
    $current_page = $this->get_pagenum();
    $total_items = count($data);
    // Set pagination arguments
    $this->set_pagination_args(array(
      'total_items' => $total_items,                  // Calculate the total number of items
      'per_page'    => $this->per_page,               // Determine how many items to show on a page
      'total_pages' => ceil($total_items / $this->per_page)  // Calculate the total number of pages
    ));

    // Slice the data array according to the current page and per_page
    $this->items = array_slice($data, (($current_page - 1) * $this->per_page), $this->per_page);
  }

  function get_columns()
  {
    return [
      'cb' => '<input type="checkbox" />', // Add this line
      'row_num' => 'No.',
      'fellowship' => 'Fellowships',
      'first_name' => 'First Name',
      'last_name' => 'Last Name',
      'phone' => 'Phone',
      'email' => 'Email',
      'times' => 'Times',
      'percentage' => 'Percentage',
      'first_attendance_date' => 'First attended Date',
      'last_attended' => 'Last Attended Date',
      'is_member' => 'Member',
    ];
  }

  function column_cb($item)
  {
    return sprintf(
      '<input type="checkbox" name="bulk-select[]" value="%s" />',
      $item['id']
    );
  }
  function get_bulk_actions()
  {
    $actions = array(
      'make_member'    => 'Make Member',
      'make_non_member' => 'Make Non-Member',
    );
    return $actions;
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
      case 'fellowship':
      case 'last_attended':
        return $item[$column_name];
      case 'is_member':
        return $item[$column_name] ? "Yes" : "No";
      case 'first_attendance_date':
        return date('d/m/Y', strtotime($item[$column_name]));
      case 'percentage':
        return $item[$column_name] . "%";
      default:
        return print_r($item, true);
    }
  }
}


function combine_attendace_with_same_email($data, $start_date, $end_date, $percentage_filter = false)
{


  $sunday_count = calculate_sunday_count($start_date, $end_date);
  // $sunday_count = 3;

  $combinedData = [];
  foreach ($data as $entry) {
    $email = $entry['email'];
    $new_start_date = date('Y-m-d', strtotime($entry['first_attendance_date']));
    if ($start_date < $new_start_date) {
      $sunday_count = calculate_sunday_count($new_start_date, $end_date);
    }
    if (!isset($combinedData[$email])) {
      // If this email doesn't exist in the combined array, add it.
      $combinedData[$email] = $entry;
      $combinedData[$email]['times'] = 1;
      if ($sunday_count <= 0) {
        $combinedData[$email]['percentage'] = 100;
      } else {
        $combinedData[$email]['percentage'] = number_format(1 / $sunday_count * 100, 2, '.', '');
      }
      $last_attended_date = get_last_attended_date($email);
      $combinedData[$email]['last_attended'] = date('d/m/Y', strtotime($last_attended_date));
      // $combinedData[$email]['last_attended'] = $last_attended_date;
    } else {
      // If this email exists, increment the times counter.
      $combinedData[$email]['times']++;
      if ($sunday_count <= 0) {
        $combinedData[$email]['percentage'] = 100;
      } else {
        $combinedData[$email]['percentage'] = number_format($combinedData[$email]['times'] / $sunday_count * 100, 2, '.', '');
      }
      // Update the fields if the current entry has a larger ID (is more recent).
      if ($entry['id'] > $combinedData[$email]['id']) {
        $combinedData[$email]['first_name'] = $entry['first_name'];
        $combinedData[$email]['last_name'] = $entry['last_name'];
        $combinedData[$email]['phone'] = $entry['phone'];
        $combinedData[$email]['fellowship'] = $entry['fellowship'];
        $combinedData[$email]['is_new'] = $entry['is_new'];
        // Add any other fields that you want to update to the latest one.
      }
      $last_attended_date = get_last_attended_date($email);
      $combinedData[$email]['last_attended'] = date('d/m/Y', strtotime($last_attended_date));
      // $combinedData[$email]['last_attended'] = $last_attended_date;
    }
  }
  $combinedData = array_values($combinedData);
  if ($percentage_filter) {
    $combinedData = array_filter($combinedData, function ($item) {
      return $item['percentage'] >= 50;
    });
  }
  foreach ($combinedData as $key => $value) {
    $combinedData[$key] = ['row_num' => $key + 1] + $value;
  }
  return $combinedData;
}

function calculate_sunday_count($start_date, $end_date)
{
  $start = new DateTime($start_date);
  $end = new DateTime($end_date);
  $interval = new DateInterval('P1D'); // 1 day interval

  $sunday_count = 0;

  while ($start <= $end) {
    if ($start->format('N') == 7) { // Sunday is day number 7
      $sunday_count++;
    }
    $start->add($interval);
  }

  return $sunday_count;
}


function get_last_attended_date($email)
{
  global $wpdb;
  $attendance_table_name = $wpdb->prefix . 'attendance';
  $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates';

  $query = $wpdb->prepare(
    "SELECT MAX(D.date_attended)
    FROM $attendance_table_name AS A
    INNER JOIN $attendance_dates_table_name AS D ON A.id = D.attendance_id
    WHERE A.email = %s",
    $email
  );

  return $wpdb->get_var($query);
}


function es_render_attendance_list()
{
  global $wpdb;
  $attendance_table_name = $wpdb->prefix . 'attendance';
  $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates';
  $start_date = current_time('Y-m-d');
  $end_date = current_time('Y-m-d');
  // Query to select attendance data based on filters
  $query = "SELECT A.*
  FROM $attendance_table_name AS A
  INNER JOIN $attendance_dates_table_name AS D ON A.id = D.attendance_id 
  WHERE 1=1";
  $query .= $wpdb->prepare(" AND is_new = %s", 1);
  $query .= $wpdb->prepare(" AND D.date_attended >= %s", $start_date);
  $query .= $wpdb->prepare(" AND D.date_attended <= %s", $end_date);
  $results = $wpdb->get_results($query, ARRAY_A);

  foreach ($results as &$item) {
    $item['start_date'] = $start_date;
    $item['end_date'] = $end_date;
  }
  $results = combine_attendace_with_same_email($results, $start_date, $end_date, false);
  $attendanceListTable = new ES_Attendance_List();
  $attendanceListTable->prepare_items($results);
?>
<div class="wrap">
  <h2>Attendance</h2>
  <div class="filter-form">
    <select name="es_fellowship_filter" id="es_fellowship_filter">
      <option value="" selected>全部</option>
      <option value="Daniel">但以理团契</option>
      <option value="True love">真爱团团契</option>
      <option value="Faith Hope Love">信望爱团契</option>
      <option value="Peace&Joy Prayer">平安喜乐祷告会</option>
      <option value="other">其他</option>
    </select>
    <select name="es_member_filter" id="es_member_filter">
      <option value="" selected>全部</option>
      <option value="isMember">会員</option>
      <option value="isNonMember">非会員</option>
    </select>
    <input type="text" id="last_name_filter" placeholder="Last Name">
    <input type="text" id="first_name_filter" placeholder="First Name">
    <input type="text" id="email_filter" placeholder="Email">
    <input type="date" id="start_date_filter" placeholder="Start Date" value="<?php echo current_time('Y-m-d'); ?>">
    <input type="date" id="end_date_filter" placeholder="End Date" value="<?php echo current_time('Y-m-d'); ?>">
    <div>
      <span class="checkbox-container">
        <input type="checkbox" id="is_new_filter" name="is_new_filter" checked>
        <label for="is_new_filter">New Attendance</label>
      </span>
      <span class="checkbox-container">
        <input type="checkbox" id="percentage_filter" name="percentage_filter">
        <label for="percentage_filter">>= 50%</label>
      </span>
      <button id="filter-button" type="button" class="submit-btn">Filter</button>
      <button id="export-csv-button" type="button" class="export-csv">Export to CSV</button>
    </div>
    <div id="filter-table-response">
      <?php $attendanceListTable->display(); ?>
      <div id="loader-box" style="display: none;">
        <div id="es-loading-spinner" class="loader"></div>
      </div>
    </div>
  </div>
  <?php
}


add_action('wp_ajax_es_filter_attendance', 'es_filter_attendance_callback');

function get_filtered_attendance_results($query)
{
  $fellowship = sanitize_text_field($_POST['fellowship']);
  $last_name = sanitize_text_field($_POST['last_name']);
  $first_name = sanitize_text_field($_POST['first_name']);
  $email = sanitize_text_field($_POST['email']);
  $is_member = sanitize_text_field($_POST['is_member']);
  $start_date = sanitize_text_field($_POST['start_date_filter']);
  $end_date = sanitize_text_field($_POST['end_date_filter']);
  $percentage_filter = isset($_POST['percentage_filter']) &&  $_POST['percentage_filter'] == 'true' ? 1 : 0;

  global $wpdb;


  if (!empty($fellowship)) {
    $query .= $wpdb->prepare(" AND fellowship = %s", $fellowship);
  }
  if (!empty($first_name)) {
    $query .= $wpdb->prepare(" AND first_name = %s", $first_name);
  }

  if (!empty($last_name)) {
    $query .= $wpdb->prepare(" AND last_name = %s", $last_name);
  }

  if (!empty($email)) {
    $query .= $wpdb->prepare(" AND email = %s", $email);
  }
  if (!empty($is_member)) {
    $is_member = $is_member == "isMember" ? 1 : 0;

    $query .= $wpdb->prepare(" AND is_member = %s", $is_member);
  }

  if (isset($_POST['is_new']) &&  $_POST['is_new'] == 'true') {
    $query .= $wpdb->prepare(" AND is_new = %s", 1);
  }
  if (!empty($start_date)) {
    $start_date = date('Y-m-d', strtotime($start_date));
    $query .= $wpdb->prepare(" AND D.date_attended >= %s", $start_date);
  }
  if (!empty($end_date)) {
    $end_date = date('Y-m-d', strtotime($end_date));
    $query .= $wpdb->prepare(" AND D.date_attended <= %s", $end_date);
  }

  $results = $wpdb->get_results($query, ARRAY_A);
  $results = combine_attendace_with_same_email($results, $start_date, $end_date, $percentage_filter);

  return $results;
}


function es_filter_attendance_callback()
{
  global $wpdb;
  $attendance_table_name = $wpdb->prefix . 'attendance';
  $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates';
  $query = "SELECT A.*
            FROM $attendance_table_name AS A
            INNER JOIN $attendance_dates_table_name AS D ON A.id = D.attendance_id 
            WHERE 1=1";

  $results = get_filtered_attendance_results($query);
  $attendanceListTable = new ES_Attendance_List();
  $attendanceListTable->prepare_items($results);

  // Output the updated table HTML
  ob_start();
  $attendanceListTable->display();
  echo '<div id="loader-box" style="display: none;"><div id="es-loading-spinner" class="loader"></div></div>';
  $table_html = ob_get_clean();

  // Send the updated table HTML as a response
  wp_send_json_success(['table_html' => $table_html]);
  wp_die();
}

add_action('admin_menu', function () {
  add_menu_page('Attendance', 'Attendance', 'manage_options', 'es-attendance', 'es_render_attendance_list', 'dashicons-calendar', 1);
});


function es_export_attendance_csv()
{
  global $wpdb;
  $attendance_table_name = $wpdb->prefix . 'attendance';
  $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates';
  $query = "SELECT A.fellowship, A.first_name,A.last_name,A.phone,A.email, A.is_member,A.first_attendance_date
    FROM $attendance_table_name AS A
    INNER JOIN $attendance_dates_table_name AS D ON A.id = D.attendance_id 
    WHERE 1=1";
  $results = get_filtered_attendance_results($query);
  $reorderedResults = [];

  foreach ($results as $row) {
    $reorderedResults[] = array(
      $row['row_num'],
      $row['fellowship'],
      $row['first_name'],
      $row['last_name'],
      $row['phone'],
      $row['email'],
      $row['times'],
      $row['percentage'] . '%',
      $row['first_attendance_date'],
      $row['last_attended'],
      $row['is_member'] == '1' ? 'Yes' : 'No',
    );
  }
  $csv_data = array();
  foreach ($reorderedResults as $row) {
    $csv_data[] = implode(',', $row);
  }
  $fileName = array(
    'No.',
    'Fellowships',
    'First Name	',
    'Last Name	',
    'Phone',
    'Email',
    'Times',
    'Percentage',
    'First attended Date',
    'Last Attended Date',
    'Member',
  );
  $csv_string = implode(',', $fileName);
  $csv_string .= "\n";
  $csv_string .= implode("\n", $csv_data);
  echo $csv_string;
  wp_die();
}
add_action('wp_ajax_es_export_attendance_csv', 'es_export_attendance_csv');


add_action('wp_ajax_handle_member_status_update', 'handle_member_status_update');

function handle_member_status_update()
{
  global $wpdb;
  $ids = isset($_POST['ids']) ? $_POST['ids'] : array();
  $action = isset($_POST['member_action']) ? $_POST['member_action'] : '';

  $is_member = ($action == 'make_member') ? 1 : 0;

  foreach ($ids as $id) {
    $wpdb->update(
      $wpdb->prefix . 'attendance',
      array('is_member' => $is_member),
      array('id' => intval($id))
    );
  }

  wp_send_json_success(array('message' => 'Member status updated successfully.'));
}


function es_on_deactivation()
{
  global $wpdb;
  if (!current_user_can('activate_plugins')) return;

  $attendance_table_name = $wpdb->prefix . 'attendance';
  $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates';

  $result1 = $wpdb->query("DROP TABLE IF EXISTS $attendance_dates_table_name");
  $result2 = $wpdb->query("DROP TABLE IF EXISTS $attendance_table_name");

  if ($result1 === false || $result2 === false) {
    error_log("Error dropping tables: " . $wpdb->last_error);
  } else {
    error_log("Tables dropped successfully.");
  }
}

register_deactivation_hook(__FILE__, 'es_on_deactivation');