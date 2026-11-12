<?php

namespace AP;

defined('ABSPATH') || exit;

class Attendance_DB
{
  /**
   * 前台提交签到
   * - 仅允许在目标星期签到
   * - 以 phone 为唯一键：首插 is_new=1，之后更新 is_new=0
   * - 出勤表对同人同日使用 INSERT IGNORE（依赖唯一键）
   * - 如果是新来宾（es_is_newcomer），同时记录到 attendance_first_time_attendance_dates
   */
  public static function handle_submit(array $post): array
  {
    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';
    $dates      = $wpdb->prefix . 'attendance_dates';
    $first_dates = $wpdb->prefix . 'attendance_first_time_attendance_dates';

    // 仅允许目标星期签到
    if ((int) current_time('w') !== get_target_dow()) {
      return ['ok' => false, 'msg' => '今天不是允许的签到日，不能签到。'];
    }

    // 输入清洗 & 归一化
    $first_name   = trim(sanitize_text_field($post['es_first_name'] ?? ''));
    $last_name    = trim(sanitize_text_field($post['es_last_name'] ?? ''));
    $country_code = trim(sanitize_text_field($post['es_phone_country_code'] ?? ''));
    $phone_number = trim(sanitize_text_field($post['es_phone_number'] ?? ''));
    $fellowship   = trim(sanitize_text_field($post['es_fellowship'] ?? ''));
    $referral_source = trim(sanitize_text_field($post['es_referral_source'] ?? ''));
    $email        = sanitize_email($post['es_email'] ?? '');
    $today        = current_time('Y-m-d');

    // 检查是否为新来宾
    $is_newcomer = isset($post['es_is_newcomer']) && (string)$post['es_is_newcomer'] === '1';

    // 去除空格/连字符/括号
    $phone_number = preg_replace('/[\s\-\(\)]/', '', $phone_number);
    // AU 本地格式：+61 去掉开头 0
    if ($country_code === '+61' && substr($phone_number, 0, 1) === '0') {
      $phone_number = substr($phone_number, 1);
    }
    $phone = $country_code . $phone_number;

    // 原子 upsert（依赖 attendance.phone 唯一键）
    $upsert_sql = $wpdb->prepare(
      "INSERT INTO $attendance
        (first_name, last_name, phone, email, fellowship, referral_source, is_new, first_attendance_date)
      VALUES (%s, %s, %s, %s, %s, %s, 1, %s)
      ON DUPLICATE KEY UPDATE
        first_name = IF(VALUES(first_name) IS NULL OR VALUES(first_name) = '', first_name, VALUES(first_name)),
        last_name  = IF(VALUES(last_name)  IS NULL OR VALUES(last_name)  = '', last_name,  VALUES(last_name)),
        email      = IF(VALUES(email)      IS NULL OR VALUES(email)      = '', email,      VALUES(email)),
        fellowship = IF(VALUES(fellowship) IS NULL OR VALUES(fellowship) = '', fellowship, VALUES(fellowship)),
        referral_source = IF(VALUES(referral_source) IS NULL OR VALUES(referral_source) = '', referral_source, VALUES(referral_source)),
        is_new     = 0",
      $first_name,
      $last_name,
      $phone,
      $email,
      $fellowship,
      $referral_source,
      $today
    );


    $ok = $wpdb->query($upsert_sql);
    if ($ok === false) {
      return ['ok' => false, 'msg' => '创建/更新用户失败，请稍后再试。'];
    }

    // 拿到人员 id（无论新建或更新）
    $aid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $attendance WHERE phone = %s", $phone));
    if (!$aid) {
      return ['ok' => false, 'msg' => '创建/更新用户失败（未找到记录）。'];
    }

    // 写入当日出勤（依赖 dates 唯一键 (attendance_id, date_attended)）
    $ins = $wpdb->query($wpdb->prepare(
      "INSERT IGNORE INTO $dates (attendance_id, date_attended) VALUES (%d, %s)",
      $aid,
      $today
    ));
    if ($ins === false) {
      return ['ok' => false, 'msg' => '记录签到失败，请稍后再试。'];
    }
    if ($ins === 0) {
      return ['ok' => false, 'msg' => '您今天已经签到过了，请勿重复签到!'];
    }

    // 已写入新出勤：让 Dashboard 缓存失效（放在这里才能覆盖下面所有 return 分支）
    self::clear_attendance_cache();

    $times_all = (int) $wpdb->get_var(
      $wpdb->prepare("SELECT COUNT(*) FROM $dates WHERE attendance_id = %d", $aid)
    );
    $force_new = isset($post['es_force_newcomer']) && (string)$post['es_force_newcomer'] === '1';

    if ($is_newcomer && $times_all > 1 && !$force_new) {
      // 老号码但勾选了新来宾：不再要求确认，直接按“强制”处理，同时返回 warn
      $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO $first_dates (attendance_id, date_attended) VALUES (%d, %s)",
        $aid,
        $today
      ));
      $affected = (int) $wpdb->rows_affected;
      return [
        'ok'       => true,
        'inserted' => ($affected > 0),
        'warn'     => 'existing_phone_checked_new',
        'last_date' => self::get_last_attended_date($phone) ?: '',
        'msg'      => ($affected > 0)
          ? '签到成功！已登记为新来宾（该号码此前已有出席记录）。'
          : '签到成功！今日已登记过为新来宾（该号码此前已有出席记录）。'
      ];
    }


    // ============ 轻确认：二次提交 或 本就不冲突 的写入 ============
    if ($is_newcomer && ($force_new || $times_all <= 1)) {
      $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO $first_dates (attendance_id, date_attended) VALUES (%d, %s)",
        $aid,
        $today
      ));
      // IGNORE 保证幂等，无需检查返回值
      return ['ok' => true, 'msg' => '签到成功！已登记为新来宾。'];
    }

    // 不勾选或选择了“不记录”
    return ['ok' => true, 'msg' => '签到成功！'];
  }

  private const CACHE_VER_OPTION = 'ap_filtered_cache_ver';

  /**
   * 让 query_filtered 的缓存全部失效
   * 用"版本号"方式：缓存键里带版本号，版本号 +1 后旧缓存自然不再命中（5 分钟后自动过期）。
   * 比直接 DELETE options 表更可靠——站点启用 Redis/Memcached 等对象缓存时 transient 不在 options 表里。
   */
  public static function clear_attendance_cache(): void
  {
    update_option(self::CACHE_VER_OPTION, (int) get_option(self::CACHE_VER_OPTION, 0) + 1, false);
  }

  /**
   * 后台/前台筛选列表查询
   * - 每人一行：times = 区间内出席次数；times_all / last_attended = 全历史
   * - is_new 过滤按"全历史仅 1 次"判断（与日期区间无关）
   * - 所有统计都在一条 SQL 里完成，避免逐行查询
   */
  public static function query_filtered(array $post): array
  {
    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';
    $dates      = $wpdb->prefix . 'attendance_dates';

    // ✅ 缓存键：版本号 + 查询参数（签到/改资料/改会员后版本号会 +1）
    $cache_ver = (int) get_option(self::CACHE_VER_OPTION, 0);
    $cache_key = 'ap_filtered_' . $cache_ver . '_' . md5(serialize($post));
    $cached = get_transient($cache_key);
    if ($cached !== false) {
      return $cached;
    }

    $fellowship = sanitize_text_field($post['fellowship'] ?? '');
    $last_name  = sanitize_text_field($post['last_name'] ?? '');
    $first_name = sanitize_text_field($post['first_name'] ?? '');
    $email      = sanitize_text_field($post['email'] ?? '');
    $phone      = sanitize_text_field($post['phone'] ?? '');
    $is_member  = sanitize_text_field($post['is_member'] ?? '');

    $raw_is_new = isset($post['is_new']) ? sanitize_text_field($post['is_new']) : '';
    $is_new     = in_array(strtolower($raw_is_new), ['1', 'true', 'on', 'yes'], true);

    $pct_filter = (!empty($post['percentage_filter']) && $post['percentage_filter'] == 'true');

    $start_raw = sanitize_text_field($post['start_date_filter'] ?? '');
    $end_raw   = sanitize_text_field($post['end_date_filter'] ?? '');
    $start = $start_raw ? date('Y-m-d', strtotime($start_raw)) : current_time('Y-m-d');
    $end   = $end_raw   ? date('Y-m-d', strtotime($end_raw))   : current_time('Y-m-d');

    // R：区间内次数（INNER JOIN => 只保留区间内出席过的人）
    // S：全历史次数 + 最后出席日
    $q = $wpdb->prepare(
      "SELECT A.*, R.times, S.times_all, S.last_attended
         FROM $attendance AS A
         INNER JOIN (
           SELECT attendance_id, COUNT(*) AS times
             FROM $dates
            WHERE date_attended BETWEEN %s AND %s
            GROUP BY attendance_id
         ) AS R ON R.attendance_id = A.id
         INNER JOIN (
           SELECT attendance_id, COUNT(*) AS times_all, MAX(date_attended) AS last_attended
             FROM $dates
            GROUP BY attendance_id
         ) AS S ON S.attendance_id = A.id
        WHERE 1=1",
      $start,
      $end
    );

    if ($fellowship) $q .= $wpdb->prepare(" AND A.fellowship = %s", $fellowship);
    if ($first_name) $q .= $wpdb->prepare(" AND A.first_name LIKE %s", '%' . $wpdb->esc_like($first_name) . '%');
    if ($last_name)  $q .= $wpdb->prepare(" AND A.last_name  LIKE %s", '%' . $wpdb->esc_like($last_name)  . '%');
    if ($phone) {
      // 兼容本地格式（如 0468869556）与国际格式（如 +61468869556 / 61468869556）：
      // 只比较数字，并去掉本地拨号前缀的那个 0，这样两种写法都能命中库里存的 +61... 号码
      $phone_digits = preg_replace('/\D+/', '', $phone);
      $phone_digits = preg_replace('/^0+/', '', $phone_digits);
      if ($phone_digits !== '') {
        $q .= $wpdb->prepare(
          " AND REPLACE(A.phone, '+', '') LIKE %s",
          '%' . $wpdb->esc_like($phone_digits) . '%'
        );
      }
    }
    if ($email) $q .= $wpdb->prepare(" AND A.email LIKE %s", '%' . $wpdb->esc_like($email) . '%');

    if ($is_member) {
      $im = ($is_member === 'isMember') ? 1 : 0;
      $q .= $wpdb->prepare(" AND A.is_member = %d", $im);
    }

    if ($is_new) {
      $q .= " AND S.times_all = 1";
    }

    $q .= " ORDER BY A.id ASC";

    $rows = $wpdb->get_results($q, ARRAY_A) ?: [];
    $rows = self::decorate_rows($rows, $start, $end, $pct_filter);

    // ✅ 缓存结果 5 分钟
    set_transient($cache_key, $rows, 5 * MINUTE_IN_SECONDS);

    return $rows;
  }

  /**
   * 查询某手机号的最后出勤日期（全历史）
   */
  public static function get_last_attended_date(string $phone): ?string
  {
    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';
    $dates      = $wpdb->prefix . 'attendance_dates';

    return $wpdb->get_var($wpdb->prepare(
      "SELECT MAX(D.date_attended)
         FROM $attendance AS A
         INNER JOIN $dates AS D ON A.id = D.attendance_id
        WHERE A.phone = %s",
      $phone
    ));
  }

  /**
   * 给每行补上展示字段
   * - percentage：times / 区间内目标星期的总次数（自该人首次出席日起算）
   * - is_new：全历史只出席过 1 次
   * - row_num：序号（在 ≥50% 过滤之后编号）
   */
  private static function decorate_rows(array $rows, string $start_date, string $end_date, bool $pct_filter): array
  {
    $target = get_target_dow();
    $out    = [];

    foreach ($rows as $row) {
      $effective_start = max($start_date, (string) $row['first_attendance_date']); // Y-m-d 可直接字符串比较
      $den   = count_weekday_between($effective_start, $end_date, $target);
      $times = (int) $row['times'];

      $row['times']         = $times;
      $row['is_new']        = ((int) $row['times_all'] === 1) ? 1 : 0;
      $row['percentage']    = ($den <= 0) ? 0 : number_format($times / $den * 100, 2, '.', '');
      $row['last_attended'] = $row['last_attended'] ?: '';

      if ($pct_filter && !($row['percentage'] >= 50)) {
        continue;
      }
      $out[] = ['row_num' => count($out) + 1] + $row;
    }

    return $out;
  }

  /**
   * 批量更新 member
   */
  public static function bulk_update_member(array $post): string
  {
    global $wpdb;
    $ids       = isset($post['ids']) ? (array)$post['ids'] : [];
    $action    = sanitize_text_field($post['member_action'] ?? '');
    $is_member = ($action === 'make_member') ? 1 : 0;

    foreach ($ids as $id) {
      $wpdb->update(
        $wpdb->prefix . 'attendance',
        ['is_member' => $is_member],
        ['id' => intval($id)],
        ['%d'],
        ['%d']
      );
    }
    self::clear_attendance_cache();
    return 'Member status updated successfully.';
  }

  /**
   * CSV 构建（首行加入日期区间标题）
   */
  public static function build_csv(array $post): string
  {
    // 与 query_filtered 一致的日期默认
    $start_raw = sanitize_text_field($post['start_date_filter'] ?? '');
    $end_raw   = sanitize_text_field($post['end_date_filter'] ?? '');

    $start = $start_raw ? date('Y-m-d', strtotime($start_raw)) : current_time('Y-m-d');
    $end   = $end_raw   ? date('Y-m-d', strtotime($end_raw))   : current_time('Y-m-d');

    $rows = self::query_filtered($post);

    // 标题：日期区间
    $lines      = [];
    $range_text = \AP\format_date_dmy($start) . ' to ' . \AP\format_date_dmy($end);
    $lines[]    = implode(',', [\AP\csv_escape('Date Range'), \AP\csv_escape($range_text)]);
    $lines[]    = '';

    // 表头
    $lines[] = implode(',', array_map('\AP\csv_escape', [
      'No.',
      'Fellowships',
      'Referral Source',
      'First Name',
      'Last Name',
      'Phone',
      'Email',
      'Times',
      'Percentage',
      'First attended Date',
      'Last Attended Date',
      'Member'
    ]));

    // 数据
    foreach ($rows as $r) {
      $f     = \AP\ap_translate_fellowship($r['fellowship']);
      $source = \AP\ap_translate_referral_source($r['referral_source'] ?? '');
      $first = \AP\format_date_dmy($r['first_attendance_date'] ?? '');
      $last  = \AP\format_date_dmy($r['last_attended'] ?? '');

      $lines[] = implode(',', array_map('\AP\csv_escape', [
        $r['row_num'],
        $f,
        $source,
        $r['first_name'],
        $r['last_name'],
        \AP\csv_excel_text($r['phone'] ?? ''),
        $r['email'],
        $r['times'],
        $r['percentage'] . '%',
        $first,
        $last,
        ($r['is_member'] == '1' ? 'Yes' : 'No')
      ]));
    }

    return implode("\n", $lines);
  }

  /**
   * 详情（返回渲染用数据）
   */
  public static function get_detail(int $attendance_id, string $start, string $end): array
  {
    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';
    $dates      = $wpdb->prefix . 'attendance_dates';

    $user = $wpdb->get_row($wpdb->prepare(
      "SELECT first_name, last_name, phone FROM $attendance WHERE id = %d",
      $attendance_id
    ));

    $list = $wpdb->get_results($wpdb->prepare(
      "SELECT date_attended
         FROM $dates
        WHERE attendance_id = %d
          AND date_attended BETWEEN %s AND %s",
      $attendance_id,
      $start,
      $end
    ), ARRAY_A);

    return ['user' => $user, 'list' => $list];
  }

  /**
   * 查询首访日志（从 attendance_first_time_attendance_dates 表）
   */
  public static function query_first_timers_log(string $start, string $end): array
  {
    global $wpdb;
    $attendance  = $wpdb->prefix . 'attendance';
    $first_dates = $wpdb->prefix . 'attendance_first_time_attendance_dates';

    // 直接读日志表；按日期区间筛选
    $sql = $wpdb->prepare(
      "SELECT A.first_name, A.last_name, A.phone, A.referral_source, D.date_attended AS first_attendance_date, D.id AS id
       FROM $attendance AS A
       INNER JOIN $first_dates AS D ON A.id = D.attendance_id
      WHERE D.date_attended BETWEEN %s AND %s
      ORDER BY D.date_attended DESC, A.last_name ASC, A.first_name ASC",
      $start,
      $end
    );

    return $wpdb->get_results($sql, ARRAY_A) ?: [];
  }
}
