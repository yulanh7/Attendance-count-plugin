<?php

namespace AP\Tests;

class AssertionFailed extends \Exception {}

function assert_same($expected, $actual, string $msg = ''): void
{
  if ($expected !== $actual) {
    throw new AssertionFailed(($msg ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
  }
}

function assert_true(bool $v, string $msg = ''): void
{
  if (!$v) throw new AssertionFailed($msg ?: 'expected true');
}

/** 测试之间清理：移除测试挂的 filter、清对象缓存、登出 */
function reset_state(): void
{
  remove_all_filters('ap_is_check_in_day');
  remove_all_filters('ap_today');
  wp_cache_flush();
  wp_set_current_user(0);
}

function make_person(array $over = []): int
{
  global $wpdb;
  $row = array_merge([
    'first_name' => 'Test',
    'last_name' => 'Person',
    'phone' => '+999' . wp_rand(10000000, 99999999),
    'email' => '',
    'fellowship' => 'other',
    'referral_source' => '',
    'is_new' => 0,
    'is_member' => 0,
    'first_attendance_date' => '2026-01-04',
    'home_congregation' => 'mandarin',
  ], $over);
  $wpdb->insert($wpdb->prefix . 'attendance', $row);
  return (int) $wpdb->insert_id;
}

function add_attendance(int $id, string $ymd, string $congregation): void
{
  global $wpdb;
  $wpdb->insert($wpdb->prefix . 'attendance_dates', [
    'attendance_id' => $id,
    'date_attended' => $ymd,
    'congregation' => $congregation,
  ]);
}

function add_newcomer(int $id, string $ymd, string $congregation): int
{
  global $wpdb;
  $wpdb->insert($wpdb->prefix . 'attendance_first_time_attendance_dates', [
    'attendance_id' => $id,
    'date_attended' => $ymd,
    'congregation' => $congregation,
  ]);
  return (int) $wpdb->insert_id;
}

function make_user(string $role, ?string $congregation): int
{
  $id = wp_insert_user([
    'user_login' => 'ap_test_' . wp_generate_password(8, false),
    'user_pass' => wp_generate_password(),
    'role' => $role,
  ]);
  if (is_wp_error($id)) throw new \Exception($id->get_error_message());
  if ($congregation !== null) update_user_meta($id, 'ap_congregation', $congregation);
  wp_set_current_user($id);
  return (int) $id;
}

function force_check_in_day(?string $today): void
{
  add_filter('ap_is_check_in_day', '__return_true');
  if ($today !== null) {
    add_filter('ap_today', fn() => $today);
  }
}
