<?php

/**
 * Plugin Name: Attendance Plugin
 * Description: A WordPress plugin to manage attendance.
 * Version: 1.34
 * Author: Rachel Huang
 */


// require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

defined('ABSPATH') or die('No script kiddies please!');

function create_attendance_table()
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'attendance';
  $charset_collate = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $table_name (
      id INT NOT NULL AUTO_INCREMENT,
      first_name VARCHAR(255) NOT NULL,
      last_name VARCHAR(255) NOT NULL,
      phone VARCHAR(20),
      email VARCHAR(255) NOT NULL,
      first_date DATE,
      last_date DATE,
      congregation VARCHAR(255) NOT NULL,
      times INT,
      is_new BOOLEAN,
      PRIMARY KEY (id)
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
}

add_action('wp_enqueue_scripts', 'es_enqueue_scripts');
add_action('admin_enqueue_scripts', 'es_enqueue_scripts');


function attendance_form()
{
  ob_start();
?>
  <form id="es_attendance_form" class="es-attendance-form">
    <input type="text" name="es_first_name" required placeholder="First Name *">
    <input type="text" name="es_last_name" required placeholder="Last Name *">
    <input type="email" name="es_email" required placeholder="Email *">
    <input type="text" name="es_phone" placeholder="Phone">
    <select name="es_congregation">
      <option value="Mandarin Congregation" selected>Mandarin Congregation</option>
      <option value="Cantonese Congregation">Cantonese Congregation</option>
      <option value="English Congregation">English Congregation</option>
    </select>
    <input type="submit" name="submit_attendance" value="Submit Attendance">
  </form>
<?php
  return ob_get_clean();
}
add_shortcode('attendance_form', 'attendance_form');


function es_handle_attendance()
{
  // Get the submitted form data
  $first_name = sanitize_text_field($_POST['es_first_name']);
  $last_name = sanitize_text_field($_POST['es_last_name']);
  $phone = sanitize_text_field($_POST['es_phone']);
  $congregation = sanitize_text_field($_POST['es_congregation']);
  $email = sanitize_email($_POST['es_email']);
  $current_date = current_time('mysql');

  // Check for duplicate entries on the same date (you should customize this query based on your database structure)
  global $wpdb;
  $table_name = $wpdb->prefix . 'attendance';

  $existing_user = $wpdb->get_row(
    $wpdb->prepare(
      "SELECT * FROM $table_name WHERE email = %s",
      $email
    ),
    ARRAY_A
  );

  $existing_entry = $wpdb->get_row(
    $wpdb->prepare(
      "SELECT * FROM $table_name WHERE email = %s AND DATE(last_date) = DATE(%s)",
      $email,
      $current_date
    ),
    ARRAY_A
  );

  if ($existing_entry) {
    wp_send_json_error(['message' => 'You have already submitted attendance for this person on the same date.']);
    return;
  }

  if ($existing_user) {
    $data = array(
      'first_name' => $first_name,
      'last_name' => $last_name,
      'congregation' => $congregation,
      'phone' => $phone,
      'last_date' => $current_date,
      'times' => intval($existing_user['times']) + 1,
      'is_new' => false,
    );
    $wpdb->update(
      $table_name,
      $data,
      array(
        'email' => $email,
        'last_date' => $existing_user['last_date'], // Only update if the last_date matches the existing record
      )
    );
  } else {
    $data = array(
      'first_name' => $first_name,
      'last_name' => $last_name,
      'phone' => $phone,
      'email' => $email,
      'first_date' => $current_date,
      'last_date' => $current_date,
      'times' => 1,
      'congregation' => $congregation,
      'is_new' => true,
    );
    $wpdb->insert($table_name, $data);
  }
  wp_send_json_success(['message' => 'Submit successfully!']);
}

add_action('wp_ajax_es_handle_attendance', 'es_handle_attendance');



if (!class_exists('WP_List_Table')) {
  require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class ES_Attendance_List extends WP_List_Table
{

  function prepare_items($data = array())
  {
    $columns = $this->get_columns();
    $hidden = array();
    $sortable = $this->get_sortable_columns();

    $this->_column_headers = array($columns, $hidden, $sortable);
    $this->items = $data; // Use the filtered data provided as a parameter
  }

  function get_columns()
  {
    return [
      'congregation' => 'Congregation',
      'first_name' => 'First Name',
      'last_name' => 'Last Name',
      'phone' => 'Phone',
      'email' => 'Email',
      'first_date' => 'First Date',
      'last_date' => 'Last Date',
      'times' => 'Times',
      'is_new' => 'Is New',
    ];
  }

  function column_default($item, $column_name)
  {
    switch ($column_name) {
      case 'is_new':
        return $item[$column_name] ? 'Yes' : 'No';

      case 'first_date':
      case 'last_date':
        $date = DateTime::createFromFormat('Y-m-d', $item[$column_name]);
        if ($date !== false) {
          return $date->format('d/m/Y');
        } else {
          return $item[$column_name];
        }

      case 'first_name':
      case 'last_name':
      case 'email':
      case 'phone':
      case 'congregation':
      case 'times':
        return $item[$column_name];

      default:
        return print_r($item, true);
    }
  }
}

function es_render_attendance_list()
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'attendance';
  $results = $wpdb->get_results("SELECT * FROM $table_name WHERE is_new = 1 AND last_date = CURDATE()", ARRAY_A);
  $attendanceListTable = new ES_Attendance_List();
  $attendanceListTable->prepare_items($results);
?>
  <div class="wrap">
    <h2>Attendance</h2>
    <div class="filter-form">
      <select name="es_congregation" id="es_congregation_filter">
        <option value="" selected>All</option>
        <option value="Mandarin Congregation">Mandarin Congregation</option>
        <option value="Cantonese Congregation">Cantonese Congregation</option>
        <option value="English Congregation">English Congregation</option>
      </select>
      <input type="text" id="last_date_filter" placeholder="Last Date" value="<?php echo date('d/m/Y'); ?>">
      <input type="text" id="last_name_filter" placeholder="Last Name">
      <input type="text" id="first_name_filter" placeholder="First Name">
      <input type="text" id="email_filter" placeholder="Email">
      <span class="checkbox-container">
        <input type="checkbox" id="is_new_filter" name="is_new_filter" checked>
        <label for="is_new_filter">New Attendance</label>
      </span>
      <button id="filter-button" type="button" class="submit-btn">Filter</button>

      <div id="filter-table-response">
        <?php $attendanceListTable->display(); ?>
      </div>
    </div>
  <?php
}


add_action('wp_ajax_es_filter_attendance', 'es_filter_attendance_callback');

function es_filter_attendance_callback()
{
  // Retrieve filter values from the AJAX request
  $last_date = sanitize_text_field($_POST['last_date']);
  $congregation = sanitize_text_field($_POST['congregation']);
  $last_name = sanitize_text_field($_POST['last_name']);
  $first_name = sanitize_text_field($_POST['first_name']);
  $email = sanitize_text_field($_POST['email']);
  $last_date_formatted = date('Y-m-d H:i:s', strtotime(str_replace('/', '-', $last_date)));
  $is_new = isset($_POST['is_new']) && $_POST['is_new'] === 'true' ? true : false; // Check the isNew value

  global $wpdb;
  $table_name = $wpdb->prefix . 'attendance';

  $query = "SELECT * FROM $table_name WHERE 1=1";

  if (!empty($congregation)) {
    $query .= $wpdb->prepare(" AND congregation = %s", $congregation);
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

  if (!empty($last_date)) {
    $query .= $wpdb->prepare(" AND DATE(last_date) = DATE(%s)", $last_date_formatted);
  }

  if ($is_new) {
    $query .= " AND is_new = 1";
  }

  $results = $wpdb->get_results($query, ARRAY_A);


  // Create a new table instance and prepare it with the filtered data
  $attendanceListTable = new ES_Attendance_List();
  $attendanceListTable->prepare_items($results);

  // Output the updated table HTML
  ob_start();
  $attendanceListTable->display();
  $table_html = ob_get_clean();

  // Send the updated table HTML as a response
  wp_send_json_success(['table_html' => $table_html]);
  wp_die();
}

add_action('admin_menu', function () {
  add_menu_page('Attendance', 'Attendance', 'manage_options', 'es-attendance', 'es_render_attendance_list', 'dashicons-calendar', 1);
});


function es_on_deactivation()
{
  if (!current_user_can('activate_plugins')) return;
  global $wpdb;
  $table_name = $wpdb->prefix . 'attendance';
  $wpdb->query("DROP TABLE IF EXISTS $table_name");
  if (isset($_GET['es_delete_table']) && $_GET['es_delete_table'] == 'true') {
  }
}

register_deactivation_hook(__FILE__, 'es_on_deactivation');

add_action('admin_notices', function () {
  if (isset($_GET['deactivate'])) {
    echo '<div class="updated"><p>Do you want to delete the attendance table? <a href="' . admin_url('plugins.php?es_delete_table=true') . '">Yes</a> | <a href="' . admin_url('plugins.php') . '">No</a></p></div>';
  }
});
