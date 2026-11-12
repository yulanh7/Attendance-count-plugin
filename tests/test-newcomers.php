<?php

use AP\Attendance_DB;
use function AP\Tests\assert_same;
use function AP\Tests\assert_true;
use function AP\Tests\make_person;
use function AP\Tests\add_attendance;
use function AP\Tests\add_newcomer;

function ap_nc_ids(array $rows): array
{
  return array_map('intval', array_column($rows, 'id'));
}

return [
  'test_newcomer_scope' => function () {
    $a = make_person();
    $b = make_person();
    $na = add_newcomer($a, '2026-11-15', 'mandarin');
    $nb = add_newcomer($b, '2026-11-15', 'cantonese');
    $rows = Attendance_DB::query_first_timers_log('2026-11-15', '2026-11-15', 'cantonese');
    assert_same([$nb], ap_nc_ids($rows));
    assert_same('cantonese', $rows[0]['congregation']);
    $all = ap_nc_ids(Attendance_DB::query_first_timers_log('2026-11-15', '2026-11-15', null));
    assert_true(in_array($na, $all, true) && in_array($nb, $all, true), 'admin sees both');
  },
  'test_newcomer_seen_elsewhere' => function () {
    $a = make_person();
    add_attendance($a, '2026-11-01', 'english');
    add_attendance($a, '2026-11-08', 'mandarin');
    add_attendance($a, '2026-11-15', 'cantonese');
    add_newcomer($a, '2026-11-15', 'cantonese');
    $fresh = make_person();
    add_attendance($fresh, '2026-11-15', 'cantonese');
    add_newcomer($fresh, '2026-11-15', 'cantonese');
    $rows = Attendance_DB::query_first_timers_log('2026-11-15', '2026-11-15', 'cantonese');
    $by = [];
    foreach ($rows as $r) $by[$r['attendance_id']] = $r['seen_elsewhere'];
    assert_same('english', $by[$a], 'earliest other congregation');
    assert_same(null, $by[$fresh]);
  },
  'test_newcomer_invalid_dates_fall_back_to_today' => function () {
    add_filter('ap_today', fn() => '2026-11-15');
    $a = make_person();
    add_newcomer($a, '2026-11-15', 'mandarin');
    $rows = Attendance_DB::query_first_timers_log('not-a-date', '2026-13-45', 'mandarin');
    assert_true(in_array($a, array_map('intval', array_column($rows, 'attendance_id')), true));
  },
  'test_delete_newcomer_rejects_other_congregation' => function () {
    global $wpdb;
    $a = make_person();
    $n = add_newcomer($a, '2026-11-15', 'mandarin');
    assert_same(false, Attendance_DB::delete_newcomer($n, 'cantonese'));
    assert_same('1', $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}attendance_first_time_attendance_dates WHERE id = %d", $n)));
    assert_same(true, Attendance_DB::delete_newcomer($n, 'mandarin'));
    $n2 = add_newcomer($a, '2026-11-22', 'english');
    assert_same(true, Attendance_DB::delete_newcomer($n2, null));
  },
  'test_first_timers_scope_by_first_attendance' => function () {
    $a = make_person(['first_attendance_date' => '2026-11-15']);
    add_attendance($a, '2026-11-15', 'cantonese');
    $c = array_column(Attendance_DB::query_first_timers('2026-11-15', '2026-11-15', 'cantonese'), 'phone');
    $m = array_column(Attendance_DB::query_first_timers('2026-11-15', '2026-11-15', 'mandarin'), 'phone');
    $phone = $GLOBALS['wpdb']->get_var("SELECT phone FROM {$GLOBALS['wpdb']->prefix}attendance WHERE id = {$a}");
    assert_true(in_array($phone, $c, true), 'in cantonese first timers');
    assert_true(!in_array($phone, $m, true), 'not in mandarin first timers');
  },
  'test_newcomer_card_shows_seen_elsewhere_and_congregation' => function () {
    $row = ['id' => 5, 'first_name' => 'Amy', 'last_name' => 'Chan', 'phone' => '+61400000000', 'referral_source' => 'friends',
      'first_attendance_date' => '2026-11-15', 'congregation' => 'cantonese', 'seen_elsewhere' => 'mandarin'];
    $html = \AP\Frontend_Page::render_first_timers_list_html([$row], true, true, true, 'zh_HK', false);
    assert_true(strpos($html, '曾在國語堂出席') !== false, 'seen elsewhere');
    assert_true(strpos($html, '認識途徑：朋友') !== false, 'referral zh_HK');
    assert_true(strpos($html, '移出新來賓名單') !== false, 'delete btn zh_HK');
    assert_true(strpos($html, 'ap-ft-congregation') === false, 'no congregation label for staff');
    $admin = \AP\Frontend_Page::render_first_timers_list_html([$row], true, true, true, 'zh_CN', true);
    assert_true(strpos($admin, '粤语堂') !== false, 'admin sees congregation');
    assert_true(strpos($admin, '首次来访：15/11/2026') !== false, 'zh_CN first visit unchanged');
  },
];
