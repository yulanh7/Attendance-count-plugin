<?php

use AP\Attendance_DB;
use function AP\Tests\assert_same;
use function AP\Tests\assert_true;
use function AP\Tests\make_person;
use function AP\Tests\add_attendance;

/** A：4 个主日里 2 次国语堂、2 次粤语堂；B：只去国语堂 */
function ap_db_fixture(): array
{
  $a = make_person(['first_attendance_date' => '2026-10-18', 'first_name' => 'Alice']);
  add_attendance($a, '2026-10-18', 'mandarin');
  add_attendance($a, '2026-10-25', 'mandarin');
  add_attendance($a, '2026-11-01', 'cantonese');
  add_attendance($a, '2026-11-08', 'cantonese');
  $b = make_person(['first_attendance_date' => '2026-10-18', 'first_name' => 'Bob']);
  add_attendance($b, '2026-10-18', 'mandarin');
  return [$a, $b];
}
function ap_db_post(array $over = []): array
{
  return array_merge(['start_date_filter' => '2026-10-18', 'end_date_filter' => '2026-11-08', '_bust' => uniqid('', true)], $over);
}
function ap_db_row(array $rows, int $id): ?array
{
  foreach ($rows as $r) if ((int) $r['id'] === $id) return $r;
  return null;
}

return [
  'test_dashboard_percentage_per_congregation_and_all' => function () {
    [$a] = ap_db_fixture();
    $post = ap_db_post();
    assert_same('50.00', ap_db_row(Attendance_DB::query_filtered($post, 'mandarin'), $a)['percentage']);
    assert_same('50.00', ap_db_row(Attendance_DB::query_filtered($post, 'cantonese'), $a)['percentage']);
    assert_same('100.00', ap_db_row(Attendance_DB::query_filtered($post, null), $a)['percentage']);
    assert_same(2, ap_db_row(Attendance_DB::query_filtered($post, 'cantonese'), $a)['times']);
    assert_same('2026-11-08', ap_db_row(Attendance_DB::query_filtered($post, null), $a)['last_attended']);
    assert_same('2026-10-25', ap_db_row(Attendance_DB::query_filtered($post, 'mandarin'), $a)['last_attended']);
  },
  'test_dashboard_subscriber_scope_excludes_other_congregation_people' => function () {
    [$a, $b] = ap_db_fixture();
    $rows = Attendance_DB::query_filtered(ap_db_post(), 'cantonese');
    assert_true(ap_db_row($rows, $a) !== null, 'A present');
    assert_same(null, ap_db_row($rows, $b));
  },
  'test_dashboard_all_scope_has_congregation_counts' => function () {
    [$a, $b] = ap_db_fixture();
    $rows = Attendance_DB::query_filtered(ap_db_post(), null);
    assert_same(['mandarin' => 2, 'cantonese' => 2], ap_db_row($rows, $a)['congregation_counts']);
    assert_same(['mandarin' => 1], ap_db_row($rows, $b)['congregation_counts']);
    assert_same(false, array_key_exists('congregation_counts', ap_db_row(Attendance_DB::query_filtered(ap_db_post(), 'mandarin'), $a)));
  },
  'test_dashboard_cache_separated_by_scope' => function () {
    [$a, $b] = ap_db_fixture();
    $post = ap_db_post(); // 同一个 $post（同一个缓存参数）
    Attendance_DB::query_filtered($post, 'mandarin');
    $rows = Attendance_DB::query_filtered($post, 'cantonese');
    assert_same(null, ap_db_row($rows, $b), 'cantonese must not reuse mandarin cache');
  },
  'test_bulk_update_rejects_out_of_scope_ids' => function () {
    global $wpdb;
    [$a, $b] = ap_db_fixture();
    Attendance_DB::bulk_update_member(['ids' => [$a, $b], 'member_action' => 'make_member'], 'cantonese');
    $m = fn($id) => $wpdb->get_var($wpdb->prepare("SELECT is_member FROM {$wpdb->prefix}attendance WHERE id = %d", $id));
    assert_same('1', $m($a));
    assert_same('0', $m($b));
    Attendance_DB::bulk_update_member(['ids' => [$b], 'member_action' => 'make_member'], null);
    assert_same('1', $m($b));
  },
  'test_detail_hides_other_congregation_dates' => function () {
    [$a] = ap_db_fixture();
    $d = Attendance_DB::get_detail($a, '2026-10-01', '2026-11-30', 'cantonese');
    assert_same(['2026-11-01', '2026-11-08'], array_column($d['list'], 'date_attended'));
    assert_same(['cantonese', 'cantonese'], array_column($d['list'], 'congregation'));
    assert_same(4, count(Attendance_DB::get_detail($a, '2026-10-01', '2026-11-30', null)['list']));
  },
  'test_detail_hides_person_outside_scope' => function () {
    [, $b] = ap_db_fixture();
    $d = Attendance_DB::get_detail($b, '2026-10-01', '2026-11-30', 'cantonese');
    assert_same(null, $d['user']);
    assert_same([], $d['list']);
  },
  'test_csv_congregation_column_admin_only' => function () {
    [$a] = ap_db_fixture();
    $csv_sub = Attendance_DB::build_csv(ap_db_post(['first_name' => 'Alice']), 'cantonese', false);
    assert_true(strpos($csv_sub, '"堂"') === false, 'subscriber csv has no congregation column');
    $csv_admin = Attendance_DB::build_csv(ap_db_post(['first_name' => 'Alice']), null, true);
    assert_true(strpos($csv_admin, '"堂"') !== false, 'admin csv has congregation column');
    assert_true(strpos($csv_admin, '国语 2、粤语 2') !== false, 'admin csv shows breakdown');
  },
  'test_dashboard_fellowship_label_by_owner' => function () {
    assert_same('伊甸團契', \AP\Congregation::fellowship_label('eden', 'zh_CN'));
  },
];
