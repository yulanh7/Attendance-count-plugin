<?php

/**
 * Template Name: Attendance Standalone
 * Description: 签到专用独立页面（无 Header/Footer）
 * 由 Attendance 插件提供（AP\Page_Templates），原在 zegen-child 主题中。
 */

// 阻止 Elementor Header/Footer
defined('ELEMENTOR_PRO_HIDE_HEADER') || define('ELEMENTOR_PRO_HIDE_HEADER', true);
defined('ELEMENTOR_PRO_HIDE_FOOTER') || define('ELEMENTOR_PRO_HIDE_FOOTER', true);

// 移除所有不必要的钩子
remove_all_actions('wp_head');
remove_all_actions('wp_footer');
remove_all_actions('wp_body_open');

// 只保留必要的核心功能
add_action('wp_head', 'wp_enqueue_scripts', 1);
add_action('wp_head', 'wp_print_styles', 8);
add_action('wp_head', 'wp_print_head_scripts', 9);
add_action('wp_head', 'wp_site_icon', 99);
add_action('wp_footer', 'wp_print_footer_scripts', 20);
add_action('wp_footer', 'wp_admin_bar_render', 1000);

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?php wp_title('|', true, 'right');
          bloginfo('name'); ?></title>
  <?php wp_head(); ?>
  <style>
    body {
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: #f5f5f5;
    }

    .attendance-wrapper {
      max-width: 1200px;
      margin: 0 auto;
      padding: 20px;
    }

    .home-link {
      display: inline-block;
      margin-bottom: 20px;
      padding: 8px 16px;
      background: #0073aa;
      color: #fff;
      text-decoration: none;
      border-radius: 4px;
      font-size: 14px;
    }

    .home-link:hover {
      background: #005177;
    }

    /* 隐藏 WordPress Admin Bar */
    #wpadminbar {
      display: none !important;
    }

    html {
      margin-top: 0 !important;
    }
  </style>
</head>

<body <?php body_class(); ?>>

  <div class="attendance-wrapper">


    <?php
    while (have_posts()) {
      the_post();
      the_content();
    }
    ?>
  </div>

  <?php wp_footer(); ?>
</body>

</html>