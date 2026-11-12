<?php

use function AP\Tests\assert_true;

return [
  'test_page_template_listed_in_page_template_dropdown' => function () {
    $templates = apply_filters('theme_page_templates', [], wp_get_theme(), null, 'page');
    assert_true(($templates['page-attendance-standalone.php'] ?? '') === 'Attendance Standalone', 'template registered by plugin');
  },
  'test_page_template_served_from_plugin' => function () {
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'tpl test', 'post_content' => 'x']);
    update_post_meta($id, '_wp_page_template', 'page-attendance-standalone.php');
    $GLOBALS['wp_query'] = new \WP_Query(['page_id' => $id]);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    $file = apply_filters('template_include', '/theme/page.php');
    assert_true($file === AP_PATH . 'templates/page-attendance-standalone.php', 'got ' . $file);
    assert_true(is_file($file), 'template file exists');
  },
  'test_page_template_other_pages_untouched' => function () {
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'plain', 'post_content' => 'x']);
    $GLOBALS['wp_query'] = new \WP_Query(['page_id' => $id]);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    assert_true(apply_filters('template_include', '/theme/page.php') === '/theme/page.php', 'plain page keeps theme template');
  },
];
