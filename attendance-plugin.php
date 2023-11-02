<?php

/**
 * Plugin Name: Attendance Plugin
 * Description: A WordPress plugin to manage attendance.
 * Version: 1.0
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
}

add_action('wp_enqueue_scripts', 'es_enqueue_scripts');


function attendance_form()
{
  ob_start();
?>
<form id="es_attendance_form" class="es-attendance-form">
  <input type="text" name="es_first_name" required placeholder="First Name *">
  <input type="text" name="es_last_name" required placeholder="Last Name *">
  <input type="email" name="es_email" required placeholder="Email *">
  <input type="text" name="es_phone" placeholder="Phone">


  <!-- Add other fields as needed -->
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
  $email = sanitize_email($_POST['es_email']);
  $current_date = current_time('mysql');

  // Check for duplicate entries on the same date (you should customize this query based on your database structure)
  global $wpdb;
  $table_name = $wpdb->prefix . 'attendance';

  $existing_user = $wpdb->get_row(
    $wpdb->prepare(
      "SELECT * FROM $table_name WHERE first_name = %s AND last_name = %s AND email = %s",
      ARRAY_A
    )
  );
  $existing_entry = $wpdb->get_row(
    $wpdb->prepare(
      "SELECT * FROM $table_name WHERE first_name = %s AND last_name = %s AND email = %s AND last_date = %s",
      ARRAY_A
    )
  );
  if ($existing_entry) {
    wp_send_json_error(['message' => 'You have already submitted attendance for this person on the same date.']);
    return;
  }

  if ($existing_user) {
    $data = array(
      'first_name' => $first_name,
      'last_name' => $last_name,
      'phone' => $phone,
      'email' => $email,
      'first_date' => $existing_user['first_date'],
      'last_date' => $current_date,
      'times' => intval($existing_user['times']) + 1,
      'is_new' => false,
    );
    $wpdb->update(
      $table_name,
      $data,
      array('email' => $email)
    );
  } else {
    $data = array(
      'first_name' => $first_name,
      'last_name' => $last_name,
      'phone' => $phone,
      'email' => $email,
      'first_date' => $current_date,
      'last_date' => '',
      'times' => 1,
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

  function get_data()
  {
    global $wpdb;
    $table_name = $wpdb->prefix . 'attendance';
    return $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);
  }

  function prepare_items()
  {
    $columns = $this->get_columns();
    $this->_column_headers = array($columns, array(), array());
    $this->items = $this->get_data();
  }

  function get_columns()
  {
    return [
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

    case 'first_name':
    case 'last_name':
    case 'email':
    case 'phone':
    case 'times':
    case 'first_date':
    case 'last_date':
      if (DateTime::createFromFormat('Y-m-d H:i:s', $item[$column_name]) !== false) {
        $date = new DateTime($item[$column_name]);
        return $date->format('d/m/Y');
      } else {
        return $item[$column_name];
      }

    default:
      return print_r($item, true);
  }
}
}

function es_render_attendance_list()
{
  $attendanceListTable = new ES_Attendance_List();
  $attendanceListTable->prepare_items();
?>
<div class="wrap">
  <h2>Attendance</h2>
  <?php $attendanceListTable->display(); ?>
</div>
<?php
}


add_action('admin_menu', function () {
  add_menu_page('Attendance', 'Attendance', 'manage_options', 'es-attendance', 'es_render_attendance_list');
});




function es_on_deactivation()
{
  if (!current_user_can('activate_plugins')) return;
  // global $wpdb;
  // $table_name = $wpdb->prefix . 'attendance';
  // $wpdb->query("DROP TABLE IF EXISTS $table_name");
  if (isset($_GET['es_delete_table']) && $_GET['es_delete_table'] == 'true') {
  }
}

register_deactivation_hook(__FILE__, 'es_on_deactivation');

add_action('admin_notices', function () {
  if (isset($_GET['deactivate'])) {
    echo '<div class="updated"><p>Do you want to delete the attendance table? <a href="' . admin_url('plugins.php?es_delete_table=true') . '">Yes</a> | <a href="' . admin_url('plugins.php') . '">No</a></p></div>';
  }
});