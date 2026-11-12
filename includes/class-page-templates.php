<?php

namespace AP;

defined('ABSPATH') || exit;

/**
 * 签到页面模板由插件提供（原来在 zegen-child 主题里），换主题不受影响。
 * 模板名沿用原文件名，已选了该模板的页面（_wp_page_template）无需改动。
 */
class Page_Templates
{
  const TEMPLATES = [
    'page-attendance-standalone.php' => 'Attendance Standalone',
  ];

  public static function register(): void
  {
    add_filter('theme_page_templates', [__CLASS__, 'add_to_dropdown']);
    add_filter('template_include', [__CLASS__, 'resolve'], 99);
  }

  public static function add_to_dropdown(array $templates): array
  {
    return array_merge($templates, self::TEMPLATES);
  }

  public static function resolve(string $template): string
  {
    if (!is_page()) return $template;
    $slug = get_page_template_slug(get_queried_object_id());
    if (!isset(self::TEMPLATES[$slug])) return $template;
    $file = AP_PATH . 'templates/' . $slug;
    return is_file($file) ? $file : $template;
  }
}
