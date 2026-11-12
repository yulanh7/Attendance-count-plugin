<?php

/**
 * 迁移对比（手动运行，不属于单元测试）：
 * 多堂改动前的 query_filtered($post) vs 现在的 query_filtered($post, 'mandarin')
 * 现有数据全部迁移为国语堂，所以两者结果（含顺序）应完全一致。
 *
 * 运行：与 bin/test.sh 相同的 PHP/socket 参数，执行
 *   wp eval-file tests/compare-mandarin.php
 */

defined('ABSPATH') || exit;

global $wpdb;

// 取多堂改动之前的版本，改类名后加载
$plugin_dir = dirname(__DIR__);
$old_src = shell_exec('cd ' . escapeshellarg($plugin_dir) . ' && git show 2561b4e:includes/class-attendance-db.php');
if (!$old_src) {
  fwrite(STDERR, "cannot read old version from git\n");
  exit(1);
}
$old_src = preg_replace('/\bclass Attendance_DB\b/', 'class Attendance_DB_Old', $old_src, 1);
$tmp = tempnam(sys_get_temp_dir(), 'ap_old_') . '.php';
file_put_contents($tmp, $old_src);
require $tmp;
unlink($tmp);

$dates = $wpdb->prefix . 'attendance_dates';
$min = $wpdb->get_var("SELECT MIN(date_attended) FROM $dates");
// 只比迁移前的数据（2026-11-12 起在各堂测试签到的记录旧版无法区分）
$max = $wpdb->get_var("SELECT MAX(date_attended) FROM $dates WHERE date_attended < '2026-11-12'");
$d90 = date('Y-m-d', strtotime($max . ' -90 days'));

$sets = [
  'full range'            => ['start_date_filter' => $min, 'end_date_filter' => $max],
  '>=50%'                 => ['start_date_filter' => $min, 'end_date_filter' => $max, 'percentage_filter' => 'true'],
  'newcomers'             => ['start_date_filter' => $min, 'end_date_filter' => $max, 'is_new' => 'true'],
  'last 90d non-members'  => ['start_date_filter' => $d90, 'end_date_filter' => $max, 'is_member' => 'no'],
  'phone 04'              => ['start_date_filter' => $min, 'end_date_filter' => $max, 'phone' => '04'],
  'single day newcomers'  => ['start_date_filter' => $max, 'end_date_filter' => $max, 'is_new' => 'true'],
];

$fields = ['id', 'times', 'percentage', 'is_new', 'last_attended', 'first_name', 'phone', 'email', 'is_member'];
$pick = function ($rows) use ($fields) {
  $out = [];
  foreach ((array) $rows as $r) {
    $r = (array) $r;
    $row = [];
    foreach ($fields as $f) $row[$f] = isset($r[$f]) ? (string) $r[$f] : null;
    $out[] = $row;
  }
  return $out;
};

$all_ok = true;
foreach ($sets as $name => $post) {
  $post['_bust'] = wp_generate_password(12, false); // 绕过缓存
  $old = $pick(\AP\Attendance_DB_Old::query_filtered($post));
  $new = $pick(\AP\Attendance_DB::query_filtered($post, 'mandarin'));

  $key = fn($r) => json_encode($r);
  $o = array_map($key, $old);
  $n = array_map($key, $new);
  $os = $o; sort($os);
  $ns = $n; sort($ns);
  $same  = $os === $ns;
  $order = $o === $n;
  $all_ok = $all_ok && $same && $order;

  printf("%-22s rows old=%d new=%d same=%s order=%s\n", $name, count($old), count($new), $same ? 'Y' : 'N', $order ? 'Y' : 'N');
  if (!$same) {
    $diff = array_slice(array_values(array_diff($os, $ns)), 0, 3);
    foreach ($diff as $d) echo "   only/changed in old: $d\n";
    $diff = array_slice(array_values(array_diff($ns, $os)), 0, 3);
    foreach ($diff as $d) echo "   only/changed in new: $d\n";
  }
}
echo $all_ok ? "ALL SAME\n" : "DIFFERENCES FOUND\n";
