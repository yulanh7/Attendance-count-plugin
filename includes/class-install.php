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

    // attendance：phone 唯一，常用筛选列加索引
    $sql = "CREATE TABLE $attendance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name VARCHAR(100) NOT NULL,
    last_name  VARCHAR(100) NOT NULL,
    phone      VARCHAR(20)  NOT NULL,
    email      VARCHAR(255) DEFAULT '',
    fellowship VARCHAR(32)  NOT NULL,
    is_new     TINYINT(1)   NOT NULL DEFAULT 1,
    is_member  TINYINT(1)   NOT NULL DEFAULT 0,
    first_attendance_date DATE NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_phone (phone),
    KEY idx_fellowship (fellowship),
    KEY idx_is_member (is_member)
  ) $charset;";

    // attendance_dates：同人同日唯一；常用列加索引
    $sql .= "CREATE TABLE $dates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attendance_id BIGINT UNSIGNED NOT NULL,
    date_attended DATE NOT NULL,
    PRIMARY KEY (id),
    KEY idx_attendance_id (attendance_id),
    KEY idx_date_attended (date_attended),
    UNIQUE KEY uniq_attendance_date (attendance_id, date_attended)
  ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    \dbDelta($sql);

    // （可选）如需确保 engine = InnoDB，可在此补一条：
    // $wpdb->query("ALTER TABLE $attendance ENGINE=InnoDB");
    // $wpdb->query("ALTER TABLE $dates ENGINE=InnoDB");
  }


  public static function deactivate()
  {
    // global $wpdb;
    // if (!current_user_can('activate_plugins')) return;

    // $attendance_table_name = $wpdb->prefix . 'attendance';
    // $attendance_dates_table_name = $wpdb->prefix . 'attendance_dates';

    // // Drop the tables
    // $result1 = $wpdb->query("DROP TABLE IF EXISTS $attendance_dates_table_name");
    // $result2 = $wpdb->query("DROP TABLE IF EXISTS $attendance_table_name");

    // if ($result1 === false || $result2 === false) {
    //   // Log an error if dropping tables fails
    //   error_log("Error dropping tables: " . $wpdb->last_error);
    // } else {
    //   // Log success message if tables are dropped successfully
    //   error_log("Tables dropped successfully.");
    // }
  }
}
