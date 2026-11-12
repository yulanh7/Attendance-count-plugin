<?php

use AP\I18n;
use function AP\Tests\assert_same;
use function AP\Tests\assert_true;

return [
  'test_i18n_all_langs_have_same_keys' => function () {
    $cn = array_keys(I18n::table('zh_CN'));
    sort($cn);
    foreach (['zh_HK', 'en'] as $lang) {
      $k = array_keys(I18n::table($lang));
      sort($k);
      assert_same($cn, $k, "keys of {$lang}");
    }
  },
  'test_i18n_no_empty_values' => function () {
    foreach (['zh_CN', 'zh_HK', 'en'] as $lang) {
      foreach (I18n::table($lang) as $k => $v) {
        assert_true(trim($v) !== '', "{$lang}.{$k} empty");
      }
    }
  },
  'test_i18n_placeholder' => function () {
    assert_same('您今天已在國語堂簽到。要改為在粵語堂簽到嗎？', \AP\t('signed_elsewhere_prompt', 'cantonese', ['existing' => '國語堂', 'here' => '粵語堂']));
  },
  'test_i18n_mandarin_keeps_existing_text' => function () {
    assert_same('快速签到', \AP\t('quick_title', 'mandarin'));
    assert_same('您今天已经签到过了，请勿重复签到!', \AP\t('already_signed', 'mandarin'));
    assert_same('首次登记并签到', \AP\t('submit_first_time', 'mandarin'));
  },
  'test_i18n_unknown_key_returns_key' => fn() => assert_same('no_such_key', I18n::t('no_such_key', 'en')),
  'test_i18n_js_keys_exist' => function () {
    $en = I18n::table('en');
    foreach (I18n::js_keys() as $k) {
      assert_true(isset($en[$k]), "js key {$k} missing");
    }
  },
  'test_i18n_every_js_key_used_in_main_js_is_exported' => function () {
    $js = file_get_contents(AP_PATH . 'assets/js/main.js');
    preg_match_all('/\bT\(\s*["\']([a-z0-9_]+)["\']/', $js, $m);
    $keys = array_unique($m[1]);
    assert_true(count($keys) > 20, 'found T() calls');
    $exported = I18n::js_keys();
    $missing = array_values(array_diff($keys, $exported));
    assert_same([], $missing, 'keys used in main.js but not exported');
  },
];
