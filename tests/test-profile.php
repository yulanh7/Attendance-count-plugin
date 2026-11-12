<?php

use AP\Attendance_DB;
use function AP\Tests\assert_same;
use function AP\Tests\make_person;

function ap_pf_row(int $id): array
{
  global $wpdb;
  return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}attendance WHERE id = %d", $id), ARRAY_A);
}
function ap_pf_post(int $id, array $over = []): array
{
  $phone = ap_pf_row($id)['phone']; // +999xxxxxxxx
  return array_merge([
    'es_first_name' => 'Amy', 'es_last_name' => 'Chan', 'es_email' => '',
    'es_fellowship' => ap_pf_row($id)['fellowship'],
    'es_phone_country_code' => '+999', 'es_phone_number' => substr($phone, 4),
  ], $over);
}

function ap_pf_newcomers(int $id, string $date): array
{
  global $wpdb;
  return $wpdb->get_col($wpdb->prepare("SELECT congregation FROM {$wpdb->prefix}attendance_first_time_attendance_dates WHERE attendance_id = %d AND date_attended = %s", $id, $date));
}

return [
  'test_profile_transfer_with_join_adds_newcomer_today' => function () {
    \AP\Tests\force_check_in_day('2026-11-15');
    $id = make_person(['fellowship' => 'daniel']);
    $res = Attendance_DB::update_profile(ap_pf_post($id, ['es_home_congregation' => 'cantonese', 'es_fellowship' => 'eden', 'es_join_newcomer' => '1']), 'cantonese');
    assert_same('ok', $res['code']);
    assert_same('cantonese', ap_pf_row($id)['home_congregation']);
    assert_same(['cantonese'], ap_pf_newcomers($id, '2026-11-15'));
  },
  'test_profile_join_ignored_when_attended_there_before' => function () {
    \AP\Tests\force_check_in_day('2026-11-15');
    $id = make_person(['fellowship' => 'daniel']);
    \AP\Tests\add_attendance($id, '2026-11-08', 'cantonese');
    Attendance_DB::update_profile(ap_pf_post($id, ['es_home_congregation' => 'cantonese', 'es_fellowship' => 'eden', 'es_join_newcomer' => '1']), 'cantonese');
    assert_same('cantonese', ap_pf_row($id)['home_congregation']);
    assert_same([], ap_pf_newcomers($id, '2026-11-15'));
  },
  'test_profile_join_allowed_when_only_today_there' => function () {
    \AP\Tests\force_check_in_day('2026-11-15');
    $id = make_person(['fellowship' => 'daniel']);
    \AP\Tests\add_attendance($id, '2026-11-08', 'mandarin');
    \AP\Tests\add_attendance($id, '2026-11-15', 'cantonese');
    Attendance_DB::update_profile(ap_pf_post($id, ['es_home_congregation' => 'cantonese', 'es_fellowship' => 'eden', 'es_join_newcomer' => '1']), 'cantonese');
    assert_same(['cantonese'], ap_pf_newcomers($id, '2026-11-15'));
  },
  'test_profile_get_lists_congregations_attended_before_today' => function () {
    \AP\Tests\force_check_in_day('2026-11-15');
    $id = make_person();
    \AP\Tests\add_attendance($id, '2026-11-01', 'mandarin');
    \AP\Tests\add_attendance($id, '2026-11-08', 'english');
    \AP\Tests\add_attendance($id, '2026-11-15', 'cantonese');
    $p = Attendance_DB::get_profile(ap_pf_row($id)['phone']);
    $got = $p['attended_before'];
    sort($got);
    assert_same(['english', 'mandarin'], $got);
  },
  'test_profile_transfer_without_join_no_newcomer' => function () {
    \AP\Tests\force_check_in_day('2026-11-15');
    $id = make_person(['fellowship' => 'daniel']);
    Attendance_DB::update_profile(ap_pf_post($id, ['es_home_congregation' => 'cantonese', 'es_fellowship' => 'eden']), 'cantonese');
    assert_same([], ap_pf_newcomers($id, '2026-11-15'));
  },
  'test_profile_join_ignored_when_home_unchanged' => function () {
    \AP\Tests\force_check_in_day('2026-11-15');
    $id = make_person(['fellowship' => 'daniel']);
    Attendance_DB::update_profile(ap_pf_post($id, ['es_home_congregation' => 'mandarin', 'es_join_newcomer' => '1']), 'mandarin');
    assert_same([], ap_pf_newcomers($id, '2026-11-15'));
  },
  'test_profile_get_includes_home' => function () {
    $id = make_person(['home_congregation' => 'english', 'fellowship' => 'bereans', 'email' => 'amy@example.com']);
    $p = Attendance_DB::get_profile(ap_pf_row($id)['phone']);
    assert_same('english', $p['home_congregation']);
    assert_same('bereans', $p['fellowship']);
    assert_same('a***@example.com', $p['email_masked']);
    assert_same(false, array_key_exists('email', $p), 'raw email must not be returned');
    assert_same(null, Attendance_DB::get_profile('+99900000002'));
  },
  'test_profile_switch_home_with_matching_fellowship' => function () {
    $id = make_person(['fellowship' => 'daniel']);
    $res = Attendance_DB::update_profile(ap_pf_post($id, ['es_home_congregation' => 'cantonese', 'es_fellowship' => 'eden']), 'cantonese');
    assert_same('ok', $res['code']);
    assert_same('cantonese', ap_pf_row($id)['home_congregation']);
    assert_same('eden', ap_pf_row($id)['fellowship']);
  },
  'test_update_rejects_fellowship_not_in_home' => function () {
    $id = make_person(['fellowship' => 'daniel']);
    $res = Attendance_DB::update_profile(ap_pf_post($id, ['es_home_congregation' => 'cantonese', 'es_fellowship' => 'daniel']), 'cantonese');
    assert_same('fellowship_mismatch', $res['code']);
    assert_same('請選擇粵語堂的團契', $res['msg']);
    assert_same('mandarin', ap_pf_row($id)['home_congregation']);
    assert_same('Test', ap_pf_row($id)['first_name']);
  },
  'test_profile_keep_cross_congregation_fellowship_when_home_unchanged' => function () {
    $id = make_person(['home_congregation' => 'cantonese', 'fellowship' => 'daniel']);
    $res = Attendance_DB::update_profile(ap_pf_post($id, ['es_first_name' => 'Ben']), 'cantonese');
    assert_same('ok', $res['code']);
    assert_same('Ben', ap_pf_row($id)['first_name']);
    assert_same('daniel', ap_pf_row($id)['fellowship']);
  },
  'test_profile_other_fellowship_always_allowed' => function () {
    $id = make_person(['fellowship' => 'daniel']);
    $res = Attendance_DB::update_profile(ap_pf_post($id, ['es_home_congregation' => 'english', 'es_fellowship' => 'other']), 'english');
    assert_same('ok', $res['code']);
  },
  'test_profile_invalid_home_rejected' => function () {
    $id = make_person();
    $res = Attendance_DB::update_profile(ap_pf_post($id, ['es_home_congregation' => 'klingon']), 'mandarin');
    assert_same('invalid_congregation', $res['code']);
  },
  'test_profile_empty_email_keeps_existing' => function () {
    $id = make_person(['email' => 'keep@example.com']);
    Attendance_DB::update_profile(ap_pf_post($id), 'mandarin');
    assert_same('keep@example.com', ap_pf_row($id)['email']);
  },
  'test_profile_missing_fields_and_not_registered' => function () {
    $id = make_person();
    assert_same('missing_fields', Attendance_DB::update_profile(ap_pf_post($id, ['es_first_name' => '']), 'mandarin')['code']);
    assert_same('not_registered', Attendance_DB::update_profile(ap_pf_post($id, ['es_phone_number' => '00000003']), 'mandarin')['code']);
  },
];
