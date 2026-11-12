<?php

use function AP\Tests\assert_true;
use function AP\Tests\make_user;

function ap_rd_has(string $html, string $needle): bool { return strpos($html, $needle) !== false; }

return [
  'test_dashboard_default_range_last_3_months' => function () {
    \AP\Tests\force_check_in_day('2026-11-12'); // 星期四
    make_user('subscriber', 'mandarin');
    $h = do_shortcode('[attendance_dashboard]');
    assert_true(ap_rd_has($h, 'id="fe_start_date_filter" value="2026-08-12"'), 'start = 3 months ago');
    assert_true(ap_rd_has($h, 'id="fe_end_date_filter" value="2026-11-12"'), 'end = today');
  },
  'test_dashboard_quick_ranges' => function () {
    \AP\Tests\force_check_in_day('2026-11-12'); // 星期四；最近一个主日 = 2026-11-08
    make_user('subscriber', 'cantonese');
    $h = do_shortcode('[attendance_dashboard]');
    assert_true(ap_rd_has($h, 'data-start="2026-11-08" data-end="2026-11-08"'), 'last sunday');
    assert_true(ap_rd_has($h, 'data-start="2026-10-12" data-end="2026-11-12"'), '1 month');
    assert_true(ap_rd_has($h, 'data-start="2026-08-12" data-end="2026-11-12"'), '3 months');
    assert_true(ap_rd_has($h, 'data-start="2026-01-01" data-end="2026-11-12"'), 'this year');
    assert_true(ap_rd_has($h, '最近一個主日') && ap_rd_has($h, '今年'), 'labels in page language');
  },
  'test_dashboard_first_load_lists_everyone_not_only_new' => function () {
    \AP\Tests\force_check_in_day('2026-11-12');
    $p = \AP\Tests\make_person(['first_name' => 'Regular' . wp_rand(1000, 9999), 'first_attendance_date' => '2026-09-06']);
    \AP\Tests\add_attendance($p, '2026-09-06', 'english');
    \AP\Tests\add_attendance($p, '2026-11-08', 'english');
    make_user('subscriber', 'english');
    $h = do_shortcode('[attendance_dashboard]');
    assert_true(ap_rd_has($h, \AP\Tests\ap_person_first_name($p)), 'person who came twice is listed on first load');
  },
  'test_render_form_cantonese_fellowships_only' => function () {
    $h = do_shortcode('[attendance_form congregation="cantonese"]');
    assert_true(ap_rd_has($h, '伊甸團契'), 'has 伊甸團契');
    assert_true(ap_rd_has($h, 'name="congregation" value="cantonese"'), 'hidden congregation');
    assert_true(ap_rd_has($h, '快速簽到'), 'zh_HK title');
    assert_true(!ap_rd_has($h, '但以理团契') && !ap_rd_has($h, 'Bereans'), 'no other congregation fellowships in select');
    assert_true(ap_rd_has($h, 'name="es_home_congregation"'), 'home congregation select');
  },
  'test_render_form_default_mandarin' => function () {
    $h = do_shortcode('[attendance_form]');
    assert_true(ap_rd_has($h, 'name="congregation" value="mandarin"'), 'mandarin hidden');
    assert_true(ap_rd_has($h, '快速签到') && ap_rd_has($h, '首次登记并签到') && ap_rd_has($h, '但以理团契'), 'mandarin text unchanged');
  },
  'test_render_form_english' => function () {
    $h = do_shortcode('[attendance_form congregation=" English "]');
    assert_true(ap_rd_has($h, 'Quick Check-in') && ap_rd_has($h, 'Northside Connect Group'), 'english');
  },
  'test_form_invalid_congregation_shows_error' => function () {
    $h = do_shortcode('[attendance_form congregation="cantonnese"]');
    assert_true(!ap_rd_has($h, '<form'), 'no form');
    assert_true(ap_rd_has($h, '未知的堂代号'), 'error message');
  },
  'test_dashboard_unassigned_message' => function () {
    make_user('subscriber', null);
    $h = do_shortcode('[attendance_dashboard]');
    assert_true(ap_rd_has($h, '尚未指定所属堂') && !ap_rd_has($h, '<table'), 'unassigned');
    $n = do_shortcode('[attendance_newcomers]');
    assert_true(ap_rd_has($n, '尚未指定所属堂') && !ap_rd_has($n, 'ap-ft-list'), 'unassigned newcomers');
  },
  'test_dashboard_admin_has_congregation_filter' => function () {
    make_user('administrator', null);
    $h = do_shortcode('[attendance_dashboard]');
    assert_true(ap_rd_has($h, 'id="fe_congregation_filter"'), 'filter');
    assert_true(ap_rd_has($h, '全部堂') && ap_rd_has($h, '伊甸團契') && ap_rd_has($h, '但以理团契'), 'all fellowships');
    $n = do_shortcode('[attendance_newcomers]');
    assert_true(ap_rd_has($n, 'id="ap-ft-congregation"'), 'newcomers filter');
  },
  'test_dashboard_subscriber_no_congregation_filter' => function () {
    make_user('subscriber', 'english');
    $h = do_shortcode('[attendance_dashboard]');
    assert_true(!ap_rd_has($h, 'fe_congregation_filter'), 'no filter');
    preg_match('#<select id="fe_fellowship_filter">(.*?)</select>#s', $h, $m);
    $sel = $m[1] ?? '';
    assert_true(ap_rd_has($sel, 'Bereans Bible Study') && !ap_rd_has($sel, '但以理团契'), 'fellowship filter: own fellowships only');
    assert_true(ap_rd_has($h, 'All fellowships'), 'english ui');
    $n = do_shortcode('[attendance_newcomers]');
    assert_true(!ap_rd_has($n, 'ap-ft-congregation"') && ap_rd_has($n, 'Refresh'), 'newcomers english, no filter');
  },
  'test_render_table_congregation_column_admin' => function () {
    make_user('administrator', null);
    ob_start();
    \AP\Admin_Page::render_table_simple([['row_num' => 1, 'id' => 1, 'fellowship' => 'eden', 'first_name' => 'A', 'last_name' => 'B', 'phone' => '', 'email' => '',
      'times' => 2, 'percentage' => '50.00', 'first_attendance_date' => '2026-11-01', 'last_attended' => '2026-11-08', 'is_member' => 0,
      'congregation_counts' => ['mandarin' => 1, 'cantonese' => 1]]], null);
    $h = ob_get_clean();
    assert_true(ap_rd_has($h, '<th>堂</th>') && ap_rd_has($h, '国语 1、粤语 1'), 'admin column');
    make_user('subscriber', 'cantonese');
    ob_start();
    \AP\Admin_Page::render_table_simple([], 'cantonese');
    assert_true(!ap_rd_has(ob_get_clean(), '<th>堂</th>'), 'no column for staff');
  },
  'test_render_js_strings_provided' => function () {
    \AP\provide_js_strings('cantonese');
    $data = wp_scripts()->get_data('ap-main', 'before');
    $js = is_array($data) ? implode("\n", $data) : (string) $data;
    assert_true(ap_rd_has($js, 'window.apI18n') && ap_rd_has($js, 'window.apCtx'), 'inline js');
    // 每个页面只注入一次（以第一个 shortcode 为准），所以这里只检查格式，不依赖测试执行顺序
    assert_true((bool) preg_match('/"congregation":"(mandarin|cantonese|english)"/', $js), 'ctx congregation');
    assert_true(ap_rd_has($js, '"promptLabels":{'), 'ctx prompt labels');
  },
];
