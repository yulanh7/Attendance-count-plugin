<?php

namespace AP;

defined('ABSPATH') || exit;

class Frontend_Page
{
  /**
   * 管理页的进入检查：未登录 → 登录链接；没有权限 / 没被指定堂 → 提示；通过 → null
   */
  private static function gate(): ?string
  {
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
    if (!Access::can_access()) {
      return '<p>' . esc_html(I18n::t('unassigned_congregation', 'zh_CN')) . '</p>';
    }
    return null;
  }

  /** 管理员的「按堂筛选」下拉框 */
  private static function congregation_filter(string $id, string $lang): string
  {
    $html = '<select id="' . esc_attr($id) . '" class="ap-congregation-filter">'
      . '<option value="" selected>' . esc_html(I18n::t('filter_all_congregations', $lang)) . '</option>';
    foreach (Congregation::all() as $c) {
      $html .= '<option value="' . esc_attr($c) . '">' . esc_html(Congregation::label($c, $lang)) . '</option>';
    }
    return $html . '</select>';
  }

  /** 团契筛选选项：管理员看全部堂（按堂分组），同工只看本堂 */
  private static function fellowship_filter_options(?string $scope, string $lang): string
  {
    $html = '<option value="" selected>' . esc_html(I18n::t('filter_all_fellowships', $lang)) . '</option>';
    if ($scope !== null) {
      foreach (Congregation::fellowships($scope) as $key => $label) {
        $html .= '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
      }
      return $html;
    }
    foreach (Congregation::all() as $c) {
      $html .= '<optgroup label="' . esc_attr(Congregation::label($c, $lang)) . '">';
      foreach (Congregation::fellowships($c) as $key => $label) {
        if ($key === 'other') continue;
        $html .= '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
      }
      $html .= '</optgroup>';
    }
    return $html . '<option value="other">' . esc_html(Congregation::fellowship_label('other', $lang)) . '</option>';
  }

  /**
   * 日期快捷按钮：key（同时是界面文字的 key）=> [开始, 结束]
   * @return array<string, array{0:string,1:string}>
   */
  private static function quick_ranges(string $today): array
  {
    $ts = strtotime($today);
    $back = ((int) date('w', $ts) - get_target_dow() + 7) % 7; // 往前几天是最近一个主日（今天就是主日则为 0）
    $last_sunday = date('Y-m-d', strtotime("-{$back} days", $ts));
    return [
      'range_last_sunday' => [$last_sunday, $last_sunday],
      'range_1m'          => [date('Y-m-d', strtotime('-1 month', $ts)), $today],
      'range_3m'          => [date('Y-m-d', strtotime('-3 months', $ts)), $today],
      'range_this_year'   => [date('Y', $ts) . '-01-01', $today],
    ];
  }

  /**
   * 短代码：[attendance_dashboard]
   * - 管理员：全部堂 + 按堂筛选；同工：只看所属堂
   * - 默认日期：最近 3 个月（出席率需要较长时间才有意义）；打开时列出所有人（与按 Filter 的结果一致）
   * - 日期旁有快捷按钮：最近一个主日 / 最近 1 个月 / 最近 3 个月 / 今年
   */
  public static function render_shortcode(): string
  {
    if ($gate = self::gate()) return $gate;

    $scope = Access::scope(null);
    $ui_c  = Access::ui_congregation();
    $lang  = Congregation::lang($ui_c);
    $admin = Access::is_admin();
    provide_js_strings($ui_c);

    $ranges = self::quick_ranges(ap_today());
    [$start, $end] = $ranges['range_3m'];

    // 首次渲染预取数据（后续筛选用 AJAX）
    $rows = Attendance_DB::query_filtered([
      'start_date_filter' => $start,
      'end_date_filter'   => $end,
    ], $scope);

    [$rows_page, $page, $pages] = Admin_Page::paginate_array($rows, 1, AP_FRONT_PER_PAGE);

    ob_start(); ?>
    <div class="wrap ap-frontend-dashboard">
      <div class="ap-quick-ranges" role="group">
        <?php foreach ($ranges as $key => [$rs, $re]): ?>
          <button type="button" class="ap-range-btn<?php echo $key === 'range_3m' ? ' is-active' : ''; ?>" data-start="<?php echo esc_attr($rs); ?>" data-end="<?php echo esc_attr($re); ?>"><?php echo esc_html(I18n::t($key, $lang)); ?></button>
        <?php endforeach; ?>
      </div>
      <div class="filter-form">
        <?php if ($admin) echo self::congregation_filter('fe_congregation_filter', $lang); ?>

        <select id="fe_fellowship_filter"><?php echo self::fellowship_filter_options($scope, $lang); ?></select>

        <select id="fe_member_filter">
          <option value="" selected><?php echo esc_html(I18n::t('filter_all_members', $lang)); ?></option>
          <option value="isMember"><?php echo esc_html(I18n::t('member_yes', $lang)); ?></option>
          <option value="isNonMember"><?php echo esc_html(I18n::t('member_no', $lang)); ?></option>
        </select>

        <input type="text" id="fe_last_name_filter" placeholder="Last Name">
        <input type="text" id="fe_first_name_filter" placeholder="First Name">
        <input type="text" id="fe_phone_filter" placeholder="Phone">
        <input type="text" id="fe_email_filter" placeholder="Email">

        <div class="date-range-group">
          <input type="date" id="fe_start_date_filter" value="<?php echo esc_attr($start); ?>">
          <span class="date-range-sep">—</span>
          <input type="date" id="fe_end_date_filter" value="<?php echo esc_attr($end); ?>">
        </div>

        <span class="checkbox-container">
          <input type="checkbox" id="fe_percentage_filter">
          <label for="fe_percentage_filter">&ge; 50%</label>
        </span>

        <button id="fe-filter-button" type="button" class="submit-btn">Filter</button>
        <button id="fe-export-csv-button" type="button" class="export-csv">Export to CSV</button>
      </div>

      <?php if (ap_user_can_manage()): ?>
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
          Admin_Page::render_table_simple($rows_page, $scope);
          Admin_Page::render_pagination_simple($page, $pages);
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
    return ap_compact_html(ob_get_clean());
  }

  /** 新来宾 / 首访名单页面的共用外框 */
  private static function render_list_page(string $start, string $end, string $list_html, int $count, array $data_attrs): string
  {
    if (!headers_sent()) {
      nocache_headers();
      header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
      header('Pragma: no-cache');
      header('Expires: 0');
      header('Vary: Cookie');
    }
    $ui_c = Access::ui_congregation();
    $lang = Congregation::lang($ui_c);
    provide_js_strings($ui_c);
    $generated_at = date_i18n('Y-m-d H:i', current_time('timestamp'));

    $attrs = '';
    foreach ($data_attrs as $k => $v) $attrs .= ' data-' . $k . '="' . esc_attr($v) . '"';

    ob_start(); ?>
    <div class="ap-first-timers-v2" id="ap-first-timers" data-count="<?php echo (int) $count; ?>"<?php echo $attrs; ?>>
      <form class="ap-ft-toolbar" onsubmit="return false;">
        <?php if (Access::is_admin()): ?>
          <div class="ap-ft-row"><?php echo self::congregation_filter('ap-ft-congregation', $lang); ?></div>
        <?php endif; ?>
        <div class="ap-ft-time-row">
          <span class="ap-ft-field" style="margin-right: 5px;">
            <span class="ap-ft-label"><?php echo esc_html(I18n::t('ft_start', $lang)); ?></span>
            <input type="date" id="ap-ft-start" aria-label="<?php echo esc_attr(I18n::t('ft_start_aria', $lang)); ?>" value="<?php echo esc_attr($start); ?>">
          </span>
          <span class="ap-ft-field">
            <span class="ap-ft-label"><?php echo esc_html(I18n::t('ft_end', $lang)); ?></span>
            <input type="date" id="ap-ft-end" aria-label="<?php echo esc_attr(I18n::t('ft_end_aria', $lang)); ?>" value="<?php echo esc_attr($end); ?>">
          </span>
        </div>
        <div class="ap-ft-row">
          <button type="button" class="button button-left" id="ap-ft-refresh"><?php echo esc_html(I18n::t('ft_refresh', $lang)); ?></button>
          <button type="button" class="button button-right" id="ap-ft-export"><?php echo esc_html(I18n::t('ft_export', $lang)); ?></button>
        </div>
        <div>
          <small id="ap-ft-note" style="margin-left:8px;color:#666;">
            <?php echo esc_html(I18n::t('ft_generated_note', $lang, ['time' => $generated_at])); ?>
          </small>
        </div>
      </form>

      <div id="ap-ft-loader" style="display:none;margin:8px 0;">
        <div class="ap-ft-spinner" aria-label="Loading" role="status"></div>
      </div>

      <div id="ap-ft-list"><?php echo $list_html; ?></div>
    </div>
  <?php
    return ap_compact_html(ob_get_clean());
  }

  /** @return array{0:string,1:string} shortcode 的 start/end 属性（无效则今天） */
  private static function shortcode_range($atts, string $tag): array
  {
    $today = current_time('Y-m-d');
    $a = shortcode_atts(['start' => $today, 'end' => $today], $atts, $tag);
    return [ap_valid_ymd((string) $a['start']) ?? $today, ap_valid_ymd((string) $a['end']) ?? $today];
  }

  /** 短代码：[attendance_first_timers]（按全教会第一次出席日期） */
  public static function render_first_timers_shortcode($atts = []): string
  {
    if ($gate = self::gate()) return $gate;
    [$start, $end] = self::shortcode_range($atts, 'attendance_first_timers');
    $rows = Attendance_DB::query_first_timers($start, $end, Access::scope(null));
    $list = self::render_first_timers_list_html($rows, true, false, false, ap_ui_lang(), false);
    return self::render_list_page($start, $end, $list, count($rows), []);
  }

  /** 短代码：[attendance_newcomers]（新来宾名单，按堂） */
  public static function render_newcomers_shortcode($atts = []): string
  {
    if ($gate = self::gate()) return $gate;
    [$start, $end] = self::shortcode_range($atts, 'attendance_newcomers');
    $rows = Attendance_DB::query_first_timers_log($start, $end, Access::scope(null));
    $list = self::render_first_timers_list_html($rows, true, true, true, ap_ui_lang(), Access::is_admin());
    return self::render_list_page($start, $end, $list, count($rows), [
      'source' => 'newcomers',
      'nonce'  => wp_create_nonce('ap_ft_delete_newcomer'),
    ]);
  }

  /**
   * 服务端渲染小卡片列表（姓名 + 首次来访日期；按需带电话、认识途径、曾在别堂出席、堂名、移出按钮）
   */
  public static function render_first_timers_list_html(array $rows, bool $can_view_phone, bool $deletable, bool $show_referral_source, string $display_lang, bool $show_congregation): string
  {
    if (empty($rows)) {
      return '<div class="ap-ft-empty">' . esc_html(I18n::t('ft_empty_first_timers', $display_lang)) . '</div>';
    }
    $cards = '';
    foreach ($rows as $r) {
      $id   = isset($r['id']) ? (int) $r['id'] : 0;
      $name = trim(($r['last_name'] ?? '') . ' ' . ($r['first_name'] ?? ''));
      $date = format_date_dmy($r['first_attendance_date'] ?? '');

      $meta = '<div class="ap-ft-meta">' . esc_html(I18n::t('ft_first_visit', $display_lang, ['date' => $date])) . '</div>';
      if ($show_congregation && !empty($r['congregation'])) {
        $meta .= '<div class="ap-ft-meta ap-ft-congregation">' . esc_html(Congregation::label($r['congregation'], $display_lang)) . '</div>';
      }
      if (!empty($r['seen_elsewhere'])) {
        $meta .= '<div class="ap-ft-meta ap-ft-seen-elsewhere">' . esc_html(I18n::t('seen_elsewhere', $display_lang, [
          'congregation' => Congregation::label($r['seen_elsewhere'], $display_lang),
        ])) . '</div>';
      }
      if ($show_referral_source) {
        $meta .= '<div class="ap-ft-meta">' . esc_html(I18n::t('ft_referral', $display_lang, [
          'source' => ap_translate_referral_source($r['referral_source'] ?? '', $display_lang),
        ])) . '</div>';
      }
      if ($can_view_phone && !empty($r['phone'])) {
        $meta .= '<div class="ap-ft-meta">📞 ' . esc_html($r['phone']) . '</div>';
      }
      if ($deletable && $id > 0) {
        $meta .= '<div class="ap-ft-actions" style="margin-top:6px;"><button type="button" class="button-link ap-ft-delete" data-id="' . esc_attr($id) . '">'
          . esc_html(I18n::t('ft_remove', $display_lang)) . '</button></div>';
      }

      $cards .= '<div class="ap-ft-card" data-card-id="' . esc_attr($id) . '">'
        . '<div class="ap-ft-name">' . esc_html($name) . '</div>'
        . $meta
        . '</div>';
    }
    return '<div class="ap-ft-grid">' . $cards . '</div>';
  }
}
