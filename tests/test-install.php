<?php

use AP\Install;
use function AP\Tests\assert_same;
use function AP\Tests\assert_true;

function ap_test_column(string $table, string $col): ?object
{
  global $wpdb;
  return $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$wpdb->prefix}{$table} LIKE %s", $col));
}

function ap_test_has_index(string $table, string $index): bool
{
  global $wpdb;
  return (bool) $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$wpdb->prefix}{$table} WHERE Key_name = %s", $index));
}

return [
  'test_ddl_columns_exist' => function () {
    Install::activate();
    Install::activate(); // 幂等：第二次不能出错
    global $wpdb;
    assert_same('', $wpdb->last_error, 'db error after activate');
    foreach ([['attendance', 'home_congregation'], ['attendance_dates', 'congregation'], ['attendance_first_time_attendance_dates', 'congregation']] as [$t, $c]) {
      $col = ap_test_column($t, $c);
      assert_true($col !== null, "{$t}.{$c} missing");
      assert_same('mandarin', $col->Default, "{$t}.{$c} default");
      assert_same('NO', $col->Null, "{$t}.{$c} nullable");
    }
    assert_true(ap_test_has_index('attendance', 'idx_home_congregation'), 'idx_home_congregation');
    assert_true(ap_test_has_index('attendance_dates', 'idx_congregation_date'), 'dates idx_congregation_date');
    assert_true(ap_test_has_index('attendance_first_time_attendance_dates', 'idx_congregation_date'), 'first idx_congregation_date');
    assert_true(ap_test_has_index('attendance_first_time_attendance_dates', 'uniq_attendance_date'), 'uniq_attendance_date kept');
  },
  'test_ddl_existing_rows_are_mandarin' => function () {
    global $wpdb;
    $p = $wpdb->prefix;
    assert_same('0', $wpdb->get_var("SELECT COUNT(*) FROM {$p}attendance WHERE home_congregation <> 'mandarin'"));
    assert_same('0', $wpdb->get_var("SELECT COUNT(*) FROM {$p}attendance_dates WHERE congregation <> 'mandarin'"));
    assert_same('0', $wpdb->get_var("SELECT COUNT(*) FROM {$p}attendance_first_time_attendance_dates WHERE congregation <> 'mandarin'"));
  },
];
