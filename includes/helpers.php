<?php

namespace AP;

defined('ABSPATH') || exit;

function ap_enqueue_assets()
{
  // 检查当前页面是否包含签到短代码
  global $post;
  $is_attendance_page = false;

  if ($post) {
    $is_attendance_page =
      has_shortcode($post->post_content, 'attendance_form') ||
      has_shortcode($post->post_content, 'attendance_dashboard') ||
      has_shortcode($post->post_content, 'attendance_first_timers') ||
      has_shortcode($post->post_content, 'attendance_newcomers');
  }

  // ✅ 如果不是签到页面，直接返回（不加载任何资源）
  if (!$is_attendance_page && !is_admin()) {
    return;
  }

  // ✅ jQuery 已经由 WordPress 核心提供，不需要重复加载
  wp_enqueue_script('jquery');

  // ✅ 主JS文件 - 添加版本号用于缓存控制
  wp_enqueue_script(
    'ap-main',
    AP_URL . 'assets/js/main.js',
    ['jquery'],
    filemtime(AP_PATH . 'assets/js/main.js'), // 使用文件修改时间作为版本号
    true
  );

  wp_localize_script('ap-main', 'esAjax', [
    'ajaxurl' => admin_url('admin-ajax.php', 'relative'),
    'nonce'   => wp_create_nonce('es_attendance_nonce'),
  ]);

  // ✅ 主样式文件 - 添加版本号
  wp_enqueue_style(
    'ap-style',
    AP_URL . 'assets/css/style.css',
    [],
    filemtime(AP_PATH . 'assets/css/style.css')
  );

  // ✅ 只在需要 datepicker 的页面加载（Dashboard 和 Admin）
  $needs_datepicker =
    (is_admin() && isset($_GET['page']) && $_GET['page'] === 'es-attendance') ||
    ($post && has_shortcode($post->post_content, 'attendance_dashboard'));

  if ($needs_datepicker) {
    wp_enqueue_script('jquery-ui-datepicker');

    // ✅ 使用本地 jQuery UI CSS（避免外部 CDN 慢）
    // 方法1：如果你有本地文件
    // wp_enqueue_style('jquery-ui-datepicker-style', AP_URL . 'assets/css/jquery-ui.min.css');

    // 方法2：如果没有，先保留 CDN 但添加超时处理
    wp_enqueue_style(
      'jquery-ui-datepicker-style',
      AP_URL . 'assets/css/jquery-ui.min.css',
      [],
      filemtime(AP_PATH . 'assets/css/jquery-ui.min.css')
    );
  }
}

// 通用：统计区间内指定星期几的次数
function count_weekday_between(string $start_date, string $end_date, int $targetDow): int
{
  $start = new \DateTime($start_date);
  $end   = new \DateTime($end_date);
  $iv = new \DateInterval('P1D');
  $n = 0;
  while ($start <= $end) {
    if ((int)$start->format('w') === $targetDow) $n++;
    $start->add($iv);
  }
  return $n;
}

// CSV 转义
function csv_escape($value): string
{
  $v = (string)$value;
  if (preg_match('/^[-+=@].*/', $v)) $v = "'" . $v; // 防 CSV 注入
  $v = str_replace('"', '""', $v);
  return '"' . $v . '"';
}

// 短代码渲染
function ap_render_form_shortcode()
{
  ob_start();
  include AP_PATH . 'templates/form.php';
  return ob_get_clean();
}

function format_date_dmy(?string $s): string
{
  if (!$s) return '';
  // 先试通用 strtotime
  $ts = strtotime($s);
  if ($ts !== false && $ts > 0) return date('d/m/Y', $ts);

  // 再精确按两种常见格式解析
  $fmts = ['Y-m-d', 'd/m/Y', 'Y/m/d', 'd-m-Y'];
  foreach ($fmts as $fmt) {
    $dt = \DateTime::createFromFormat($fmt, $s);
    if ($dt) return $dt->format('d/m/Y');
  }
  return '';
}

function ap_translate_fellowship($key)
{
  $map = [
    'daniel'        => '但以理团契',
    'trueLove'      => '真爱团团契',
    'faithHopeLove' => '信望爱团契',
    'peaceJoyPrayer' => '平安喜乐祷告会',
    'other'         => '其他'
  ];
  return $map[$key] ?? $key;
}


if (!function_exists('ap_truthy')) {
  function ap_truthy($v): bool
  {
    if (is_bool($v)) return $v;
    $v = strtolower((string)$v);
    return in_array($v, ['1', 'true', 'on', 'yes'], true);
  }
}
