<?php

/**
 * Plugin Name: Attendance Plugin
 * Description: Manage attendance (split structure).
 * Version: 2.4.4
 * Author: Rachel Huang
 * Text Domain: attendance-plugin
 */

defined('ABSPATH') || exit;

define('AP_PATH', plugin_dir_path(__FILE__));
define('AP_URL',  plugin_dir_url(__FILE__));

require_once AP_PATH . 'includes/class-install.php';

// 让 IDE 不报未定义常量；实际可用 wp-config 覆盖
if (!defined('ES_ATTENDANCE_DOW')) {
  define('ES_ATTENDANCE_DOW', 6); // 0=周日, 1=周一,... 3=周三
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
    add_shortcode('attendance_first_timers_log', ['AP\Frontend_Page', 'render_first_timers_log_shortcode']);
    // 新增：教会新来宾记录
    add_shortcode('attendance_newcomers', ['AP\Frontend_Page', 'render_newcomers_shortcode']);
  });
});

add_action('template_redirect', function () {
  if (!is_singular()) return;

  global $post;
  if (!$post) return;

  // 这些页面都需要登录（subscriber+）
  $needs_auth =
    has_shortcode($post->post_content ?? '', 'attendance_dashboard') ||
    has_shortcode($post->post_content ?? '', 'attendance_first_timers') ||
    has_shortcode($post->post_content ?? '', 'attendance_newcomers');

  if (!$needs_auth) return;

  if (!is_user_logged_in() || !current_user_can('read')) {
    // 跳到登录页，登录后自动回到当前页
    wp_redirect(wp_login_url(get_permalink($post)));
    exit;
  }
});
