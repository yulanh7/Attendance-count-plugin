<?php

use AP\Congregation;
use function AP\Tests\assert_same;

return [
  'test_all_codes' => fn() => assert_same(['mandarin', 'cantonese', 'english'], Congregation::all()),
  'test_parse_strict' => function () {
    assert_same('cantonese', Congregation::parse_strict(' Cantonese '));
    assert_same(null, Congregation::parse_strict('cantonnese'));
    assert_same(null, Congregation::parse_strict(''));
    assert_same(null, Congregation::parse_strict(null));
  },
  'test_from_request' => function () {
    assert_same('mandarin', Congregation::from_request([]));
    assert_same(false, Congregation::from_request(['congregation' => 'xx']));
    assert_same('english', Congregation::from_request(['congregation' => 'english']));
  },
  'test_fellowships_verbatim' => function () {
    assert_same(['fiveLoavesTwoFish' => '五餅二魚團契', 'eden' => '伊甸團契', 'evergreen' => '長青團契', 'mercy' => '恩慈團契', 'other' => '其他'], Congregation::fellowships('cantonese'));
    assert_same(['timothyYouth' => 'Timothy Youth Fellowship', 'bereans' => 'Bereans Bible Study', 'southside' => 'Southside Bible Study', 'newMercies' => 'New Mercies Fellowship', 'northsideConnect' => 'Northside Connect Group', 'other' => 'Other'], Congregation::fellowships('english'));
    assert_same(['daniel' => '但以理团契', 'trueLove' => '真爱团团契', 'faithHopeLove' => '信望爱团契', 'peaceJoyPrayer' => '平安喜乐祷告会', 'other' => '其他'], Congregation::fellowships('mandarin'));
  },
  'test_fellowship_owner_and_label' => function () {
    assert_same('cantonese', Congregation::fellowship_owner('eden'));
    assert_same(null, Congregation::fellowship_owner('other'));
    assert_same('Other', Congregation::fellowship_label('other', 'en'));
    assert_same('伊甸團契', Congregation::fellowship_label('eden', 'zh_CN'));
    assert_same('unknownKey', Congregation::fellowship_label('unknownKey', 'zh_CN'));
  },
  'test_lang_and_label' => function () {
    assert_same('zh_HK', Congregation::lang('cantonese'));
    assert_same('粵語堂', Congregation::label('cantonese', 'zh_HK'));
    assert_same('国语堂', Congregation::label('mandarin', 'zh_CN'));
  },
];
