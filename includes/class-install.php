<?php

namespace AP;

defined('ABSPATH') || exit;

class Install
{
  public static function activate()
  {
    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';
    $dates      = $wpdb->prefix . 'attendance_dates';
    $charset    = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $attendance (
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
    ) $charset;";

    $sql .= "CREATE TABLE $dates (
      id INT NOT NULL AUTO_INCREMENT,
      attendance_id INT NOT NULL,
      date_attended DATE NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY unique_attendance_date (attendance_id, date_attended),
      FOREIGN KEY (attendance_id) REFERENCES $attendance(id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    \dbDelta($sql);
  }

  public static function deactivate()
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
}
