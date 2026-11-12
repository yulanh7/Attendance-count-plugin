<?php

namespace AP;

defined('ABSPATH') || exit;

/**
 * 堂与团契注册表
 * - 堂代号：mandarin / cantonese / english
 * - 每个堂有固定的界面语言和团契名单（文字用该堂的语言）
 */
class Congregation
{
  const MANDARIN  = 'mandarin';
  const CANTONESE = 'cantonese';
  const ENGLISH   = 'english';
  const DEFAULT   = self::MANDARIN;

  private const LANGS = [
    self::MANDARIN  => 'zh_CN',
    self::CANTONESE => 'zh_HK',
    self::ENGLISH   => 'en',
  ];

  private const LABELS = [
    'zh_CN' => [self::MANDARIN => '国语堂', self::CANTONESE => '粤语堂', self::ENGLISH => '英文堂'],
    'zh_HK' => [self::MANDARIN => '國語堂', self::CANTONESE => '粵語堂', self::ENGLISH => '英文堂'],
    'en'    => [self::MANDARIN => 'Mandarin Congregation', self::CANTONESE => 'Cantonese Congregation', self::ENGLISH => 'English Congregation'],
  ];

  // 「其他」各语言共用代号 other，放在每个堂名单的最后
  private const OTHER = ['zh_CN' => '其他', 'zh_HK' => '其他', 'en' => 'Other'];

  private const FELLOWSHIPS = [
    self::MANDARIN => [
      'daniel'         => '但以理团契',
      'trueLove'       => '真爱团团契',
      'faithHopeLove'  => '信望爱团契',
      'peaceJoyPrayer' => '平安喜乐祷告会',
    ],
    self::CANTONESE => [
      'fiveLoavesTwoFish' => '五餅二魚團契',
      'eden'              => '伊甸團契',
      'evergreen'         => '長青團契',
      'mercy'             => '恩慈團契',
    ],
    self::ENGLISH => [
      'timothyYouth'     => 'Timothy Youth Fellowship',
      'bereans'          => 'Bereans Bible Study',
      'southside'        => 'Southside Bible Study',
      'newMercies'       => 'New Mercies Fellowship',
      'northsideConnect' => 'Northside Connect Group',
    ],
  ];

  /** @return string[] */
  public static function all(): array
  {
    return [self::MANDARIN, self::CANTONESE, self::ENGLISH];
  }

  public static function is_valid(string $c): bool
  {
    return in_array($c, self::all(), true);
  }

  /** 宽松输入（去空格、不分大小写）→ 有效代号；否则 null */
  public static function parse_strict(?string $raw): ?string
  {
    $c = strtolower(trim((string) $raw));
    return self::is_valid($c) ? $c : null;
  }

  /**
   * 从请求里取堂：没带字段 → 默认国语堂（兼容旧版 JS）；带了但无效 → false
   * @return string|false
   */
  public static function from_request(array $post, string $field = 'congregation')
  {
    if (!array_key_exists($field, $post)) {
      return self::DEFAULT;
    }
    return self::parse_strict(is_scalar($post[$field]) ? (string) $post[$field] : '') ?? false;
  }

  public static function lang(string $c): string
  {
    return self::LANGS[$c] ?? self::LANGS[self::DEFAULT];
  }

  public static function label(string $c, string $display_lang): string
  {
    return self::LABELS[$display_lang][$c] ?? $c;
  }

  /** @return array<string,string> 代号 => 该堂语言的文字，other 在最后 */
  public static function fellowships(string $c): array
  {
    $list = self::FELLOWSHIPS[$c] ?? [];
    $list['other'] = self::OTHER[self::lang($c)];
    return $list;
  }

  public static function fellowship_label(string $key, string $display_lang): string
  {
    if ($key === 'other') {
      return self::OTHER[$display_lang] ?? self::OTHER['zh_CN'];
    }
    foreach (self::FELLOWSHIPS as $list) {
      if (isset($list[$key])) return $list[$key];
    }
    return $key;
  }

  /** 团契属于哪个堂；other 不属于任何堂 → null */
  public static function fellowship_owner(string $key): ?string
  {
    foreach (self::FELLOWSHIPS as $c => $list) {
      if (isset($list[$key])) return $c;
    }
    return null;
  }
}
