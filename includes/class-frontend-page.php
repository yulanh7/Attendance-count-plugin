<?php

namespace AP;

defined('ABSPATH') || exit;

class Frontend_Page
{
  /**
   * 短代码：[attendance_dashboard]
   * - 登录且具备 read 权限（订阅者及以上）可见
   * - 默认筛选：今天～今天，且 is_new = true
   * - 前台使用简单表格渲染（不依赖 WP_List_Table）
   */
  public static function render_shortcode(): string
  {
    // 权限兜底（真正的未登录跳转请用入口的 template_redirect）
    if (!is_user_logged_in()) {
      $login_url = wp_login_url(get_permalink());
      return '<p>' . sprintf(
        esc_html__('Please %s to view this page.', 'attendance-plugin'),
        '<a href="' . esc_url($login_url) . '">' . esc_html__('log in', 'attendance-plugin') . '</a>'
      ) . '</p>';
    }
    if (!current_user_can('read')) {
      return '<p>' . esc_html__('You do not have permission to view this page.', 'attendance-plugin') . '</p>';
    }

    // 默认日期：今天～今天；默认 is_new = true
    $start = current_time('Y-m-d');
    $end   = current_time('Y-m-d');

    // 首次渲染预取数据（后续筛选用 AJAX）
    $rows = Attendance_DB::query_filtered([
      'start_date_filter' => $start,
      'end_date_filter'   => $end,
      'is_new'            => 'true',
    ]);

    [$rows_page, $page, $pages] = \AP\Admin_Page::paginate_array($rows, 1, AP_FRONT_PER_PAGE);

    ob_start(); ?>
    <div class="wrap ap-frontend-dashboard">
      <!-- <h2><?php echo esc_html__('Attendance', 'attendance-plugin'); ?></h2> -->

      <div class="filter-form">
        <select id="fe_fellowship_filter">
          <option value="" selected>全部团契</option>
          <option value="Daniel">但以理团契</option>
          <option value="True love">真爱团团契</option>
          <option value="Faith Hope Love">信望爱团契</option>
          <option value="Peace&Joy Prayer">平安喜乐祷告会</option>
          <option value="other">其他</option>
        </select>

        <select id="fe_member_filter">
          <option value="" selected>全部会友</option>
          <option value="isMember">会員</option>
          <option value="isNonMember">非会員</option>
        </select>

        <input type="text" id="fe_last_name_filter" placeholder="Last Name">
        <input type="text" id="fe_first_name_filter" placeholder="First Name">
        <input type="text" id="fe_phone_filter" placeholder="Phone">
        <input type="text" id="fe_email_filter" placeholder="Email">

        <span style="display:inline-block;">
          <input type="date" id="fe_start_date_filter" value="<?php echo esc_attr($start); ?>"> —
          <input type="date" id="fe_end_date_filter" value="<?php echo esc_attr($end); ?>">
        </span>

        <div>
          <span class="checkbox-container">
            <input type="checkbox" id="fe_is_new_filter" checked>
            <label for="fe_is_new_filter">New Attendance</label>
          </span>
          <span class="checkbox-container">
            <input type="checkbox" id="fe_percentage_filter">
            <label for="fe_percentage_filter">&ge; 50%</label>
          </span>

          <button id="fe-filter-button" type="button" class="submit-btn">Filter</button>
          <button id="fe-export-csv-button" type="button" class="export-csv">Export to CSV</button>
        </div>

      </div>
      <?php if (current_user_can('manage_options')): ?>
        <div class="bulk-action-bar" style="margin-top:8px;">
          <select id="fe-bulk-action-selector">
            <option value=""><?php esc_html_e('Bulk actions', 'attendance-plugin'); ?></option>
            <option value="make_member"><?php esc_html_e('Make Member', 'attendance-plugin'); ?></option>
            <option value="make_non_member"><?php esc_html_e('Make Non-Member', 'attendance-plugin'); ?></option>
          </select>
          <button id="fe-doaction" class="button action"><?php esc_html_e('Apply', 'attendance-plugin'); ?></button>
        </div>
      <?php endif; ?>

      <div id="filter-table-response">
        <div id="filter-table-response">
          <div id="table-wrap">
            <?php
            \AP\Admin_Page::render_table_simple($rows_page);
            \AP\Admin_Page::render_pagination_simple($page, $pages);
            ?>
          </div>

          <!-- 🔒 保持为 table-wrap 的兄弟节点，不会被 .html(...) 覆盖 -->
          <div id="loader-box" style="display:none;">
            <div id="es-loading-spinner" class="loader"></div>
          </div>
        </div>


        <div id="attendance-info-modal" class="popup">
          <div class="popup-content">
            <span class="close">&times;</span>
            <div id="attendance-info-modal-content"></div>
          </div>
        </div>
      </div>

  <?php
    return ob_get_clean();
  }
}
