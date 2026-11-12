<?php

use AP\Attendance_DB;
use AP\Congregation;
use function AP\Tests\assert_same;
use function AP\Tests\assert_true;
use function AP\Tests\make_person;
use function AP\Tests\add_attendance;
use function AP\Tests\force_check_in_day;

function ap_ci_person(int $id): array
{
  global $wpdb;
  return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}attendance WHERE id = %d", $id), ARRAY_A);
}
function ap_ci_phone(int $id): string { return ap_ci_person($id)['phone']; }
function ap_ci_today_rows(int $id, string $date): array
{
  global $wpdb;
  return $wpdb->get_col($wpdb->prepare("SELECT congregation FROM {$wpdb->prefix}attendance_dates WHERE attendance_id = %d AND date_attended = %s", $id, $date));
}
function ap_ci_newcomers(int $id, ?string $c = null): int
{
  global $wpdb;
  $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}attendance_first_time_attendance_dates WHERE attendance_id = %d", $id);
  if ($c !== null) $sql .= $wpdb->prepare(" AND congregation = %s", $c);
  return (int) $wpdb->get_var($sql);
}
function ap_ci_register(string $c, bool $newcomer, ?string $number = null): array
{
  $number = $number ?? (string) wp_rand(10000000, 99999999);
  $res = Attendance_DB::handle_submit([
    'es_first_name' => 'New', 'es_last_name' => 'Comer',
    'es_phone_country_code' => '+999', 'es_phone_number' => $number,
    'es_fellowship' => 'other', 'es_referral_source' => 'friends', 'es_email' => '',
    'es_is_newcomer' => $newcomer ? '1' : '0',
  ], $c);
  $res['_phone'] = '+999' . $number;
  return $res;
}
function ap_ci_id_by_phone(string $phone): int
{
  global $wpdb;
  return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}attendance WHERE phone = %s", $phone));
}

return [
  'test_check_in_new_person_home_is_page_congregation' => function () {
    force_check_in_day('2026-11-15');
    $res = ap_ci_register('cantonese', true);
    assert_same('ok', $res['code']);
    $id = ap_ci_id_by_phone($res['_phone']);
    assert_same('cantonese', ap_ci_person($id)['home_congregation']);
    assert_same(['cantonese'], ap_ci_today_rows($id, '2026-11-15'));
    assert_same(1, ap_ci_newcomers($id, 'cantonese'));
    assert_same(false, $res['suggest_switch']);
  },
  'test_check_in_existing_person_first_time_other_congregation' => function () {
    force_check_in_day('2026-11-15');
    $id = make_person();
    add_attendance($id, '2026-11-08', 'mandarin');
    $res = Attendance_DB::quick_check_in(ap_ci_phone($id), 'cantonese');
    assert_same('ok', $res['code']);
    assert_same(true, $res['first_at_congregation']);
    assert_same(true, $res['suggest_switch']);
    assert_same('mandarin', $res['home_congregation']);
    // 不再自动加入新来宾名单（转堂时本人在修改资料里选择）
    assert_same(0, ap_ci_newcomers($id));
    assert_true(strpos($res['msg'], '粵語堂') !== false, 'welcome mentions 粵語堂: ' . $res['msg']);
  },
  'test_check_in_every_visit_outside_home_suggests_switch' => function () {
    force_check_in_day('2026-11-15');
    $id = make_person();
    add_attendance($id, '2026-11-08', 'mandarin');
    Attendance_DB::quick_check_in(ap_ci_phone($id), 'cantonese');
    \AP\Tests\reset_state();
    force_check_in_day('2026-11-22');
    $res = Attendance_DB::quick_check_in(ap_ci_phone($id), 'cantonese');
    assert_same('ok', $res['code']);
    assert_same(false, $res['first_at_congregation']);
    assert_same(true, $res['suggest_switch']);
    assert_same(0, ap_ci_newcomers($id));
  },
  'test_check_in_at_home_never_suggests_switch' => function () {
    force_check_in_day('2026-11-15');
    $id = make_person();
    add_attendance($id, '2026-11-08', 'mandarin');
    $res = Attendance_DB::quick_check_in(ap_ci_phone($id), 'mandarin');
    assert_same(false, $res['suggest_switch']);
  },
  'test_check_in_first_time_at_home_congregation_no_switch' => function () {
    force_check_in_day('2026-11-15');
    $id = make_person(['home_congregation' => 'cantonese']);
    add_attendance($id, '2026-11-08', 'mandarin');
    $res = Attendance_DB::quick_check_in(ap_ci_phone($id), 'cantonese');
    assert_same(true, $res['first_at_congregation']);
    assert_same(false, $res['suggest_switch']);
  },
  'test_check_in_same_congregation_twice_already_signed' => function () {
    force_check_in_day('2026-11-15');
    $id = make_person();
    Attendance_DB::quick_check_in(ap_ci_phone($id), 'mandarin');
    $res = Attendance_DB::quick_check_in(ap_ci_phone($id), 'mandarin');
    assert_same('already_signed', $res['code']);
    assert_same(false, $res['ok']);
  },
  'test_signed_elsewhere_prompt_uses_page_language_only' => function () {
    force_check_in_day('2026-11-15');
    $id = make_person();
    $p = ap_ci_phone($id);
    Attendance_DB::quick_check_in($p, 'mandarin');

    $res = Attendance_DB::quick_check_in($p, 'cantonese');
    assert_same('今天已在國語堂簽到，要改到粵語堂嗎？', $res['msg']);
    assert_true(!isset($res['move_yes_label']), 'no bilingual button labels');

    $res = Attendance_DB::quick_check_in($p, 'english');
    assert_same('Already checked in at Mandarin today. Switch to English?', $res['msg']);
  },
  'test_signed_elsewhere_offers_transfer_only_when_useful' => function () {
    force_check_in_day('2026-11-15');
    // 老会友（国语堂）今天先在国语堂签到，再去粤语堂 → 可以「以后都在粤语堂」
    $id = make_person();
    add_attendance($id, '2026-11-08', 'mandarin');
    Attendance_DB::quick_check_in(ap_ci_phone($id), 'mandarin');
    $res = Attendance_DB::quick_check_in(ap_ci_phone($id), 'cantonese');
    assert_same('mandarin', $res['home_congregation']);
    assert_same(true, $res['can_transfer']);

    // 所属堂就是粤语堂 → 不需要转堂选项
    $id2 = make_person(['home_congregation' => 'cantonese']);
    add_attendance($id2, '2026-11-08', 'cantonese');
    Attendance_DB::quick_check_in(ap_ci_phone($id2), 'mandarin');
    assert_same(false, Attendance_DB::quick_check_in(ap_ci_phone($id2), 'cantonese')['can_transfer']);

    // 今天才登记的人也一样要问：只是这次，还是以后都在这里
    $reg = ap_ci_register('mandarin', false);
    assert_same(true, Attendance_DB::quick_check_in($reg['_phone'], 'cantonese')['can_transfer']);
  },
  'test_second_check_in_same_day_other_congregation_asks' => function () {
    force_check_in_day('2026-11-15');
    $id = make_person();
    add_attendance($id, '2026-11-08', 'mandarin');
    Attendance_DB::quick_check_in(ap_ci_phone($id), 'mandarin');
    $res = Attendance_DB::quick_check_in(ap_ci_phone($id), 'cantonese');
    assert_same('signed_elsewhere_today', $res['code']);
    assert_same('mandarin', $res['existing_congregation']);
    assert_true(strpos($res['msg'], '國語堂') !== false, $res['msg']);
    assert_same(['mandarin'], ap_ci_today_rows($id, '2026-11-15'));
    assert_same(0, ap_ci_newcomers($id, 'cantonese'));
  },
  'test_move_today_existing_person_no_auto_newcomer' => function () {
    force_check_in_day('2026-11-15');
    $id = make_person();
    add_attendance($id, '2026-11-08', 'mandarin');
    Attendance_DB::quick_check_in(ap_ci_phone($id), 'cantonese');
    $res = Attendance_DB::move_today(ap_ci_phone($id), 'mandarin');
    assert_same('ok', $res['code']);
    assert_same(['mandarin'], ap_ci_today_rows($id, '2026-11-15'));
    assert_same(0, ap_ci_newcomers($id));
    assert_same(false, $res['suggest_switch']);
  },
  'test_move_today_carries_ticked_newcomer_row' => function () {
    force_check_in_day('2026-11-15');
    $id = make_person();
    add_attendance($id, '2026-11-08', 'mandarin');
    $num = substr(ap_ci_phone($id), 4);
    ap_ci_register('cantonese', true, $num); // 已登记的人在首次登记勾了新来宾
    assert_same(1, ap_ci_newcomers($id, 'cantonese'));
    Attendance_DB::move_today(ap_ci_phone($id), 'english');
    assert_same(0, ap_ci_newcomers($id, 'cantonese'));
    assert_same(1, ap_ci_newcomers($id, 'english'));
  },
  'test_first_time_form_existing_person_keeps_fellowship_and_referral' => function () {
    force_check_in_day('2026-11-15');
    $id = make_person(['fellowship' => 'daniel', 'referral_source' => 'family']);
    ap_ci_register('mandarin', false, substr(ap_ci_phone($id), 4));
    assert_same('daniel', ap_ci_person($id)['fellowship']);
    assert_same('family', ap_ci_person($id)['referral_source']);
  },
  'test_move_today_brand_new_person' => function () {
    force_check_in_day('2026-11-15');
    $reg = ap_ci_register('mandarin', true);
    $id = ap_ci_id_by_phone($reg['_phone']);
    $res = Attendance_DB::move_today($reg['_phone'], 'cantonese');
    assert_same('ok', $res['code']);
    // 改签到不会自动转堂（转堂只在修改资料里做）；勾选过的新来宾记录跟着签到走
    assert_same('mandarin', ap_ci_person($id)['home_congregation']);
    assert_same(1, ap_ci_newcomers($id, 'cantonese'));
    assert_same(0, ap_ci_newcomers($id, 'mandarin'));
  },
  'test_move_today_brand_new_not_newcomer_stays_off_list' => function () {
    force_check_in_day('2026-11-15');
    $reg = ap_ci_register('mandarin', false);
    $id = ap_ci_id_by_phone($reg['_phone']);
    $res = Attendance_DB::move_today($reg['_phone'], 'cantonese');
    assert_same('ok', $res['code']);
    assert_same('mandarin', ap_ci_person($id)['home_congregation']);
    assert_same(0, ap_ci_newcomers($id));
  },
  'test_move_twice_back_and_forth' => function () {
    force_check_in_day('2026-11-15');
    $id = make_person();
    add_attendance($id, '2026-11-08', 'mandarin');
    $p = ap_ci_phone($id);
    Attendance_DB::quick_check_in($p, 'cantonese');
    Attendance_DB::move_today($p, 'mandarin');
    $res = Attendance_DB::move_today($p, 'cantonese');
    assert_same(['cantonese'], ap_ci_today_rows($id, '2026-11-15'));
    assert_same(0, ap_ci_newcomers($id));
    assert_same(true, $res['suggest_switch']);
  },
  'test_move_today_nothing_to_move' => function () {
    force_check_in_day('2026-11-15');
    $id = make_person();
    assert_same('nothing_to_move', Attendance_DB::move_today(ap_ci_phone($id), 'cantonese')['code']);
    Attendance_DB::quick_check_in(ap_ci_phone($id), 'cantonese');
    assert_same('nothing_to_move', Attendance_DB::move_today(ap_ci_phone($id), 'cantonese')['code']);
  },
  'test_new_registration_checkbox_unchecked_no_newcomer' => function () {
    force_check_in_day('2026-11-15');
    $res = ap_ci_register('english', false);
    $id = ap_ci_id_by_phone($res['_phone']);
    assert_same('ok', $res['code']);
    assert_same(0, ap_ci_newcomers($id));
  },
  'test_check_in_rejects_invalid_congregation' => function () {
    assert_same(false, Congregation::from_request(['congregation' => 'xx']));
  },
  'test_not_check_in_day' => function () {
    add_filter('ap_is_check_in_day', '__return_false');
    $id = make_person();
    assert_same('not_check_in_day', Attendance_DB::quick_check_in(ap_ci_phone($id), 'mandarin')['code']);
    assert_same('not_check_in_day', ap_ci_register('mandarin', false)['code']);
  },
  'test_check_in_not_registered' => function () {
    force_check_in_day('2026-11-15');
    assert_same('not_registered', Attendance_DB::quick_check_in('+99900000001', 'mandarin')['code']);
  },
];
