<?php

/**
 * Plugin Name: Attendance Plugin
 * Description: Manage attendance (split structure).
 * Version: 2.5.8
 * Author: Rachel Huang
 * Text Domain: attendance-plugin
 */

defined('ABSPATH') || exit;

define('AP_PATH', plugin_dir_path(__FILE__));
define('AP_URL',  plugin_dir_url(__FILE__));

require_once AP_PATH . 'includes/class-install.php';

// 让 IDE 不报未定义常量；实际可用 wp-config 覆盖
if (!defined('ES_ATTENDANCE_DOW')) {
  define('ES_ATTENDANCE_DOW', 7); // 0=周日, 1=周一,... 3=周三
}

if (!defined('AP_FRONT_PER_PAGE')) {
  define('AP_FRONT_PER_PAGE', 30); // 前台每页 10 条，自己调
}


// 加载模块
require_once AP_PATH . 'includes/constants.php';
require_once AP_PATH . 'includes/helpers.php';
require_once AP_PATH . 'includes/class-attendance-db.php';
require_once AP_PATH . 'includes/class-admin-page.php';
require_once AP_PATH . 'includes/class-ajax.php';
require_once AP_PATH . 'includes/class-frontend-page.php';


// 生命周期
register_activation_hook(__FILE__, ['AP\\Install', 'activate']);
register_deactivation_hook(__FILE__, ['AP\\Install', 'deactivate']);

add_action('plugins_loaded', ['AP\\Install', 'maybe_upgrade'], 5);

// 启动：资源、短代码、菜单、AJAX
add_action('plugins_loaded', function () {
  // 资源
  add_action('wp_enqueue_scripts', 'AP\\ap_enqueue_assets');
  add_action('admin_enqueue_scripts', 'AP\\ap_enqueue_assets');

  // 菜单
  // add_action('admin_menu', ['AP\\Admin_Page', 'register']);
  // AJAX
  AP\Ajax::register();

  // 短代码
  add_action('init', function () {
    add_shortcode('attendance_form', 'AP\\ap_render_form_shortcode');
    add_shortcode('attendance_dashboard', ['AP\\Frontend_Page', 'render_shortcode']);
    add_shortcode('attendance_first_timers', ['AP\\Frontend_Page', 'render_first_timers_shortcode']);
    add_shortcode('attendance_newcomers', ['AP\Frontend_Page', 'render_newcomers_shortcode']);
  });
});



add_action('template_redirect', function () {
  if (!is_singular()) return;

  global $post;
  if (!$post) return;

  // 检查是否是签到相关页面
  $is_attendance_page =
    has_shortcode($post->post_content ?? '', 'attendance_form');
  // has_shortcode($post->post_content ?? '', 'attendance_dashboard') ||
  // has_shortcode($post->post_content ?? '', 'attendance_first_timers') ||
  // has_shortcode($post->post_content ?? '', 'attendance_newcomers');

  if (!$is_attendance_page) return;

  // ✅ 禁用主题和其他插件的非关键资源
  add_action('wp_print_styles', function () {
    global $wp_styles;

    // 保留关键样式
    $keep = [
      'wp-block-library',      // WordPress 核心块样式
      'ap-style',              // 你的插件样式
      'jquery-ui-datepicker-style', // jQuery UI
    ];

    if (isset($wp_styles->queue)) {
      foreach ($wp_styles->queue as $handle) {
        // 如果不在保留列表中，移除
        if (
          !in_array($handle, $keep) &&
          strpos($handle, 'admin') === false
        ) { // 保留 admin 相关
          wp_dequeue_style($handle);
        }
      }
    }
  }, 100); // 优先级设高，确保在其他插件之后执行

  add_action('wp_print_scripts', function () {
    global $wp_scripts;

    // 保留关键脚本
    $keep = [
      'jquery',
      'jquery-ui-datepicker',
      'ap-main',              // 你的插件 JS
    ];

    if (isset($wp_scripts->queue)) {
      foreach ($wp_scripts->queue as $handle) {
        if (
          !in_array($handle, $keep) &&
          strpos($handle, 'admin') === false
        ) {
          wp_dequeue_script($handle);
        }
      }
    }
  }, 100);
}, 1); // 优先级设为1，尽早执行
