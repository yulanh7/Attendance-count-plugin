<?php

namespace AP;

defined('ABSPATH') || exit;

function ap_enqueue_assets()
{
  wp_enqueue_script('jquery');
  wp_enqueue_script('ap-main', AP_URL . 'assets/js/main.js', ['jquery'], '1.0', true);
  wp_localize_script('ap-main', 'esAjax', [
    'ajaxurl' => admin_url('admin-ajax.php'),
    'nonce'   => wp_create_nonce('es_attendance_nonce'),
  ]);
  wp_enqueue_style('ap-style', AP_URL . 'assets/css/style.css', [], '1.0');
  wp_enqueue_script('jquery-ui-datepicker');
  wp_enqueue_style('jquery-ui-datepicker-style', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
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
