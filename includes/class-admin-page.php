<?php

namespace AP;

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
  require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Admin_List_Table extends \WP_List_Table
{
  public $per_page = 20;
  public $items = [];
  // ✅ 可选：设定 singular/plural（更规范）
  public function __construct()
  {
    parent::__construct([
      'singular' => 'attendance',
      'plural'   => 'attendances',
      'ajax'     => false,
    ]);
  }

  // ✅ 就放在这个类里
  public function get_bulk_actions()
  {
    return [
      'make_member'     => 'Make Member',
      'make_non_member' => 'Make Non-Member',
    ];
  }

  public function prepare_items($data = [])
  {
    $columns = $this->get_columns();
    $hidden = [];
    $sortable = [];
    $this->_column_headers = [$columns, $hidden, $sortable];
    $current_page = $this->get_pagenum();
    $total_items = count($data);
    $this->set_pagination_args([
      'total_items' => $total_items,
      'per_page' => $this->per_page,
      'total_pages' => ceil($total_items / $this->per_page)
    ]);
    $this->items = array_slice($data, (($current_page - 1) * $this->per_page), $this->per_page);
  }

  public function get_columns()
  {
    return [
      'cb' => '<input type="checkbox" />',
      'row_num' => 'No.',
      'fellowship' => 'Fellowships',
      'first_name' => 'First Name',
      'last_name' => 'Last Name',
      'phone' => 'Phone',
      'email' => 'Email',
      'times' => 'Times',
      'percentage' => 'Percentage',
      'first_attendance_date' => 'First attended Date',
      'last_attended' => 'Last Attended Date',
      'is_member' => 'Member',
      'view_attendance' => 'View Attendance',
    ];
  }

  public function column_cb($item)
  {
    return sprintf('<input type="checkbox" name="bulk-select[]" value="%s" />', esc_attr($item['id']));
  }

  public function column_default($item, $col)
  {
    switch ($col) {
      case 'view_attendance':
        return '<button  type="button" class="view-attendance-button" data-attendance-id="' . esc_attr($item['id']) . '">View</button>';
      case 'fellowship':
        return [
          'daniel' => '但以理团契',
          'trueLove' => '真爱团团契',
          'faithHopeLove' => '信望爱团契',
          'peaceJoyPrayer' => '平安喜乐祷告会',
          'other' => '其他'
        ][$item[$col]] ?? '';
      case 'is_member':
        return !empty($item[$col]) ? 'Yes' : 'No';
      case 'first_attendance_date':
        return esc_html(\AP\format_date_dmy($item[$col]));
      case 'last_attended':
        return esc_html(\AP\format_date_dmy($item[$col]));
      case 'percentage':
        return esc_html($item[$col] . '%');
      default:
        return esc_html($item[$col] ?? '');
    }
  }
}

class Admin_Page
{
  public static function register()
  {
    add_menu_page('Attendance', 'Attendance', 'read', 'es-attendance', [__CLASS__, 'render'], 'dashicons-calendar', 1);
  }

  public static function paginate_array(array $rows, int $page = 1, int $per_page = 10): array
  {
    $total = count($rows);
    $pages = max(1, (int) ceil($total / $per_page));
    $page  = max(1, min($pages, $page));
    $offset = ($page - 1) * $per_page;
    $rows_page = array_slice($rows, $offset, $per_page);
    return [$rows_page, $page, $pages];
  }

  public static function render_pagination_simple(int $page, int $pages): void
  {
    if ($pages <= 1) return;

    $first = 1;
    $prev  = max(1, $page - 1);
    $next  = min($pages, $page + 1);
    $last  = $pages;

    echo '<nav class="ap-pager" aria-label="Pagination" data-page="' . esc_attr($page) . '" data-pages="' . esc_attr($pages) . '">';

    // First / Prev
    $disabled_prev = $page <= 1 ? ' disabled' : '';
    echo '<a href="#" class="ap-page-first' . $disabled_prev . '" data-page="' . esc_attr($first) . '" aria-disabled="' . ($page <= 1 ? 'true' : 'false') . '">«</a>';
    echo '<a href="#" class="ap-page-prev'  . $disabled_prev . '" data-page="' . esc_attr($prev)  . '" aria-disabled="' . ($page <= 1 ? 'true' : 'false') . '">‹</a>';

    // 数字页码（最多显示 7 个，当前页居中）
    $window = 7;
    $half   = (int) floor($window / 2);
    $start  = max(1, $page - $half);
    $end    = min($pages, max($start + $window - 1, $page + $half));
    $start  = max(1, min($start, $end - $window + 1));

    for ($i = $start; $i <= $end; $i++) {
      $is_current = $i === $page;
      $class = 'ap-page-num';
      if ($is_current) $class .= ' active disabled'; // 当前页
      echo '<a href="#" class="' . esc_attr($class) . '" data-page="' . esc_attr($i) . '" aria-disabled="' . ($is_current ? 'true' : 'false') . '">' . esc_html($i) . '</a>';
    }



    // Next / Last
    $disabled_next = $page >= $pages ? ' disabled' : '';
    echo '<a href="#" class="ap-page-next' . $disabled_next . '" data-page="' . esc_attr($next) . '" aria-disabled="' . ($page >= $pages ? 'true' : 'false') . '">›</a>';
    echo '<a href="#" class="ap-page-last' . $disabled_next . '" data-page="' . esc_attr($last) . '" aria-disabled="' . ($page >= $pages ? 'true' : 'false') . '">»</a>';

    echo '</nav>';
  }



  public static function render()
  {
    // 默认当天到当天
    $start = current_time('Y-m-d');
    $end   = current_time('Y-m-d');
    $rows = Attendance_DB::query_filtered([
      'start_date_filter' => $start,
      'end_date_filter' => $end
    ]);

    $table = new Admin_List_Table();
    $table->prepare_items($rows);

?>
    <div class="wrap">
      <h2>Attendance</h2>
      <div class="filter-form">
        <select id="es_fellowship_filter">
          <option value="" selected>全部团契</option>
          <option value="Daniel">但以理团契</option>
          <option value="True love">真爱团团契</option>
          <option value="Faith Hope Love">信望爱团契</option>
          <option value="Peace&Joy Prayer">平安喜乐祷告会</option>
          <option value="other">其他</option>
        </select>
        <select id="es_member_filter">
          <option value="" selected>全部会友</option>
          <option value="isMember">会員</option>
          <option value="isNonMember">非会員</option>
        </select>
        <input type="text" id="last_name_filter" placeholder="Last Name">
        <input type="text" id="first_name_filter" placeholder="First Name">
        <input type="text" id="phone_filter" placeholder="phone">
        <input type="text" id="email_filter" placeholder="Email">
        <span>
          <input type="date" id="start_date_filter" value="<?php echo esc_attr($start); ?>"> --
          <input type="date" id="end_date_filter" value="<?php echo esc_attr($end); ?>">
        </span>
        <div>
          <span class="checkbox-container">
            <input type="checkbox" id="is_new_filter"><label for="is_new_filter">New Attendance</label>
          </span>
          <span class="checkbox-container">
            <input type="checkbox" id="percentage_filter"><label for="percentage_filter">&gt;= 50%</label>
          </span>
          <button id="filter-button" type="button" class="submit-btn">Filter</button>
          <button id="export-csv-button" type="button" class="export-csv">Export to CSV</button>
        </div>
        <div id="filter-table-response">
          <form method="post" id="attendance-bulk-form">
            <?php $table->display(); ?>
          </form>
          <div id="loader-box" style="display:none;">
            <div id="es-loading-spinner" class="loader"></div>
          </div>
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
  }

  // AJAX 渲染表格
  public static function render_table(array $rows)
  {
    $t = new Admin_List_Table();
    $t->prepare_items($rows);
    echo '<form method="post" id="attendance-bulk-form">';
    $t->display();
    echo '</form>';
  }


  public static function render_table_simple(array $rows)
  {
    $is_admin = current_user_can('manage_options');
  ?>
    <?php if ($is_admin): ?>
      <form method="post" id="attendance-bulk-form">
      <?php endif; ?>

      <table class="ap-table">
        <thead>
          <tr>
            <?php if ($is_admin): ?>
              <th><input type="checkbox" id="fe-check-all"></th>
            <?php endif; ?>
            <th>No.</th>
            <th>Fellowships</th>
            <th>First Name</th>
            <th>Last Name</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Times</th>
            <th>Percentage</th>
            <th>First attended Date</th>
            <th>Last Attended Date</th>
            <th>Member</th>
            <th>View</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="<?php echo $is_admin ? 13 : 12; ?>">No data</td>
            </tr>
            <?php else: foreach ($rows as $item): ?>
              <tr>
                <?php if ($is_admin): ?>
                  <td><input type="checkbox" class="fe-check-item" name="bulk-select[]" value="<?php echo esc_attr($item['id']); ?>"></td>
                <?php endif; ?>
                <td><?php echo esc_html($item['row_num']); ?></td>
                <td><?php echo esc_html(ap_translate_fellowship($item['fellowship'])); ?></td>
                <td><?php echo esc_html($item['first_name']); ?></td>
                <td><?php echo esc_html($item['last_name']); ?></td>
                <td><?php echo esc_html($item['phone']); ?></td>
                <td><?php echo esc_html($item['email']); ?></td>
                <td><?php echo esc_html($item['times']); ?></td>
                <td><?php echo esc_html($item['percentage']) . '%'; ?></td>
                <td><?php echo esc_html(\AP\format_date_dmy($item['first_attendance_date'] ?? '')); ?></td>
                <td><?php echo esc_html(\AP\format_date_dmy($item['last_attended'] ?? '')); ?></td>
                <td><?php echo !empty($item['is_member']) ? 'Yes' : 'No'; ?></td>
                <td><button type="button" class="view-attendance-button" data-attendance-id="<?php echo esc_attr($item['id']); ?>">View</button></td>
              </tr>
          <?php endforeach;
          endif; ?>
        </tbody>
      </table>

  <?php
  }
}
