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
      <div class="filter-form">
        <select id="fe_fellowship_filter">
          <option value="" selected>全部团契</option>
          <option value="daniel">但以理团契</option>
          <option value="trueLove">真爱团团契</option>
          <option value="faithHopeLove">信望爱团契</option>
          <option value="peaceJoyPrayer">平安喜乐祷告会</option>
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
        <div id="table-wrap">
          <?php
          \AP\Admin_Page::render_table_simple($rows_page);
          \AP\Admin_Page::render_pagination_simple($page, $pages);
          ?>
        </div>

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

  public static function render_first_timers_shortcode($atts = []): string
  {
    if (!headers_sent()) {
      nocache_headers();
      header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
      header('Pragma: no-cache');
      header('Expires: 0');
      header('Vary: Cookie');
    }

    $today = current_time('Y-m-d');

    $a = shortcode_atts([
      'start' => $today,
      'end'   => $today,
    ], $atts, 'attendance_first_timers');

    // 规范化日期
    $to_date = function ($s) {
      $dt = \DateTime::createFromFormat('Y-m-d', $s);
      return $dt ? $dt->format('Y-m-d') : null;
    };
    $start = $to_date($a['start']) ?: $today;
    $end   = $to_date($a['end'])   ?: $today;

    // 权限：登录且具备 read（subscriber 及以上）可看电话
    $can_view_phone = (is_user_logged_in() && current_user_can('read'));

    // —— 服务器端首屏渲染一份（避免首屏空白），后续用 AJAX 刷新 ——
    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';
    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT first_name, last_name, phone, first_attendance_date
         FROM {$attendance}
        WHERE first_attendance_date BETWEEN %s AND %s
        ORDER BY first_attendance_date DESC, last_name, first_name",
        $start,
        $end
      ),
      ARRAY_A
    );

    $list_html = self::render_first_timers_list_html($rows, $can_view_phone);

    // UI：筛选 + 按钮 + 列表容器（列表有服务端首屏，按钮点了走 AJAX 替换）
    ob_start(); ?>
    <div class="ap-first-timers-v2" id="ap-first-timers">
      <form class="ap-ft-toolbar" onsubmit="return false;">
        <label>开始：
          <input type="date" id="ap-ft-start" value="<?php echo esc_attr($start); ?>">
        </label>
        <label>结束：
          <input type="date" id="ap-ft-end" value="<?php echo esc_attr($end); ?>">
        </label>
        <button type="button" class="button" id="ap-ft-refresh">刷新数据</button>
        <button type="button" class="button button-primary" id="ap-ft-export">导出Excel</button>
        <small id="ap-ft-note" style="margin-left:8px;color:#666;">无需刷新整页，点击“刷新数据”获取最新。</small>
      </form>

      <div id="ap-ft-loader" style="display:none;margin:8px 0;">
        <div class="loader" aria-label="Loading" role="status"></div>
      </div>

      <div id="ap-ft-list"><?php echo $list_html; ?></div>
    </div>

    <style>
      .ap-first-timers-v2 .ap-ft-toolbar {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 8px;
      }

      .ap-ft-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 12px;
        margin-top: 8px;
      }

      .ap-ft-card {
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
        padding: 10px 12px;
      }

      .ap-ft-name {
        font-weight: 600;
      }

      .ap-ft-meta {
        font-size: 12px;
        color: #666;
        margin-top: 2px;
      }

      .ap-ft-empty {
        padding: 12px;
        border: 1px dashed #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
      }

      .loader {
        width: 20px;
        height: 20px;
        border: 3px solid #ddd;
        border-top-color: #666;
        border-radius: 50%;
        animation: apspin 1s linear infinite;
      }

      @keyframes apspin {
        to {
          transform: rotate(360deg)
        }
      }

      @media print {
        .ap-ft-card {
          box-shadow: none;
          border: 0
        }
      }
    </style>
<?php
    return ob_get_clean();
  }

  /** 服务端渲染小卡片列表（姓名 + 首次来访日期；按权限可带电话） */
  public static function render_first_timers_list_html(array $rows, bool $can_view_phone): string
  {
    if (empty($rows)) {
      return '<div class="ap-ft-empty">所选日期内暂无第一次来访的朋友。</div>';
    }
    $cards = '';
    foreach ($rows as $r) {
      $name = trim(($r['last_name'] ?? '') . ' ' . ($r['first_name'] ?? ''));
      $date = \AP\format_date_dmy($r['first_attendance_date'] ?? '');
      $phone = $can_view_phone ? esc_html($r['phone'] ?? '') : '';
      $phoneHtml = $phone ? '<div class="ap-ft-meta">📞 ' . $phone . '</div>' : '';
      $cards .= '<div class="ap-ft-card">'
        .   '<div class="ap-ft-name">' . esc_html($name) . '</div>'
        .   '<div class="ap-ft-meta">首次来访：' . esc_html($date) . '</div>'
        .    $phoneHtml
        . '</div>';
    }
    return '<div class="ap-ft-grid">' . $cards . '</div>';
  }
}
