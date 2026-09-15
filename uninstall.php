<?php

// 防止直接访问：只能由 WordPress 卸载流程触发
if (!defined('WP_UNINSTALL_PLUGIN')) {
  exit;
}

// 若不想在卸载插件时删除数据，可在 wp-config.php 中添加：
// define('AP_DELETE_DATA_ON_UNINSTALL', false);
if (defined('AP_DELETE_DATA_ON_UNINSTALL') && AP_DELETE_DATA_ON_UNINSTALL === false) {
  return;
}

global $wpdb;

// 删除插件的三张自定义表
$tables = [
  $wpdb->prefix . 'attendance',
  $wpdb->prefix . 'attendance_dates',
  $wpdb->prefix . 'attendance_first_time_attendance_dates',
];
foreach ($tables as $table) {
  $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// 删除版本号 option
delete_option('ap_attendance_schema_ver');

// 清理筛选查询用的瞬态缓存（ap_filtered_*）
$wpdb->query(
  "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_ap\_filtered\_%'
        OR option_name LIKE '\_transient\_timeout\_ap\_filtered\_%'"
);
