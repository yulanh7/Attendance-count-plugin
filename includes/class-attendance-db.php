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
        (first_name, last_name, phone, email, fellowship, is_new, first_attendance_date)
      VALUES (%s, %s, %s, %s, %s, 1, %s)
      ON DUPLICATE KEY UPDATE
        first_name = IF(VALUES(first_name) IS NULL OR VALUES(first_name) = '', first_name, VALUES(first_name)),
        last_name  = IF(VALUES(last_name)  IS NULL OR VALUES(last_name)  = '', last_name,  VALUES(last_name)),
        email      = IF(VALUES(email)      IS NULL OR VALUES(email)      = '', email,      VALUES(email)),
        fellowship = IF(VALUES(fellowship) IS NULL OR VALUES(fellowship) = '', fellowship, VALUES(fellowship)),
        is_new     = 0",
      $first_name,
      $last_name,
      $phone,
      $email,
      $fellowship,
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

  /**
   * 后台/前台筛选列表查询
   * - 连接出勤表以应用日期过滤
   * - 额外带出 times_all（全历史次数）供 is_new 计算
   * - is_new 过滤按"全历史仅 1 次"判断（与日期区间无关）
   */
  public static function query_filtered(array $post): array
  {
    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';
    $dates      = $wpdb->prefix . 'attendance_dates';

    $q = "SELECT A.*,
                 (SELECT COUNT(*) FROM $dates WHERE attendance_id = A.id) AS times_all
          FROM $attendance AS A
          INNER JOIN $dates AS D ON A.id = D.attendance_id
          WHERE 1=1";

    $fellowship = sanitize_text_field($post['fellowship'] ?? '');
    $last_name  = sanitize_text_field($post['last_name'] ?? '');
    $first_name = sanitize_text_field($post['first_name'] ?? '');
    $email      = sanitize_text_field($post['email'] ?? '');
    $phone      = sanitize_text_field($post['phone'] ?? '');
    $is_member  = sanitize_text_field($post['is_member'] ?? '');

    $raw_is_new = isset($post['is_new']) ? sanitize_text_field($post['is_new']) : '';
    $is_new     = in_array(strtolower($raw_is_new), ['1', 'true', 'on', 'yes'], true);

    $pct_filter = (!empty($post['percentage_filter']) && $post['percentage_filter'] == 'true') ? 1 : 0;

    if ($fellowship) $q .= $wpdb->prepare(" AND fellowship = %s", $fellowship);
    if ($first_name) $q .= $wpdb->prepare(" AND first_name LIKE %s", '%' . $wpdb->esc_like($first_name) . '%');
    if ($last_name)  $q .= $wpdb->prepare(" AND last_name  LIKE %s", '%' . $wpdb->esc_like($last_name)  . '%');
    if ($phone)      $q .= $wpdb->prepare(" AND phone      LIKE %s", '%' . $wpdb->esc_like($phone)      . '%');
    if ($email)      $q .= $wpdb->prepare(" AND email      LIKE %s", '%' . $wpdb->esc_like($email)      . '%');

    if ($is_member) {
      $im = ($is_member === 'isMember') ? 1 : 0;
      $q .= $wpdb->prepare(" AND is_member = %d", $im);
    }

    // 日期过滤（默认当天到当天）
    $start_raw = sanitize_text_field($post['start_date_filter'] ?? '');
    $end_raw   = sanitize_text_field($post['end_date_filter'] ?? '');

    if ($start_raw) {
      $start = date('Y-m-d', strtotime($start_raw));
      $q    .= $wpdb->prepare(" AND D.date_attended >= %s", $start);
    } else {
      $start = current_time('Y-m-d');
      $q    .= $wpdb->prepare(" AND D.date_attended >= %s", $start);
    }

    if ($end_raw) {
      $end = date('Y-m-d', strtotime($end_raw));
      $q  .= $wpdb->prepare(" AND D.date_attended <= %s", $end);
    } else {
      $end = current_time('Y-m-d');
      $q  .= $wpdb->prepare(" AND D.date_attended <= %s", $end);
    }

    // "新出席"过滤：按全历史只来过 1 次（与日期筛选无关）
    if ($is_new) {
      $q .= " AND A.id IN (
               SELECT attendance_id
               FROM $dates
               GROUP BY attendance_id
               HAVING COUNT(*) = 1
             )";
    }

    $rows = $wpdb->get_results($q, ARRAY_A);

    // 合并同手机号并计算 times/percentage/last_attended
    $rows = self::combine_by_phone($rows, $start, $end, $pct_filter);

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
   * 合并同手机号记录
   * - times：当前筛选区间内的次数
   * - percentage：times / 该区间内目标星期的总次数（自有效起始日起算）
   * - is_new：基于 times_all（全历史总次数）在首次遇到该手机号时确定
   * - last_attended：全历史最大日期（Y-m-d）
   */
  public static function combine_by_phone(array $data, string $start_date, string $end_date, bool $pct_filter): array
  {
    $target = get_target_dow();
    $out    = [];

    foreach ($data as $entry) {
      $phone = $entry['phone'];

      // 计算百分比分母
      $first_dt_for_user = new \DateTime($entry['first_attendance_date']);
      $start_dt          = new \DateTime($start_date);
      $effective_start   = ($start_dt < $first_dt_for_user) ? $first_dt_for_user : $start_dt;
      $den               = count_weekday_between($effective_start->format('Y-m-d'), $end_date, $target);

      if (!isset($out[$phone])) {
        // 首次遇到该手机号
        $out[$phone]              = $entry;
        $out[$phone]['times']     = 1;
        $out[$phone]['is_new']    = (isset($entry['times_all']) && (int)$entry['times_all'] === 1) ? 1 : 0;
        $out[$phone]['percentage'] = ($den <= 0) ? 0 : number_format(1 / $den * 100, 2, '.', '');
      } else {
        // 已有：区间内累加
        $out[$phone]['times']++;
        $out[$phone]['percentage'] = ($den <= 0) ? 0 : number_format($out[$phone]['times'] / $den * 100, 2, '.', '');

        // 用较新记录更新展示字段（不覆盖 is_new）
        if ($entry['id'] > $out[$phone]['id']) {
          $out[$phone]['first_name'] = $entry['first_name'];
          $out[$phone]['last_name']  = $entry['last_name'];
          $out[$phone]['phone']      = $entry['phone'];
          $out[$phone]['fellowship'] = $entry['fellowship'];
        }
      }

      // 全历史最后出勤日
      $last = self::get_last_attended_date($phone);
      $out[$phone]['last_attended'] = $last ?: '';
    }

    $out = array_values($out);

    if ($pct_filter) {
      $out = array_filter($out, fn($r) => is_numeric($r['percentage']) && $r['percentage'] >= 50);
    }

    foreach ($out as $i => $row) {
      $out[$i] = ['row_num' => $i + 1] + $row;
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
      $first = \AP\format_date_dmy($r['first_attendance_date'] ?? '');
      $last  = \AP\format_date_dmy($r['last_attended'] ?? '');

      $lines[] = implode(',', array_map('\AP\csv_escape', [
        $r['row_num'],
        $f,
        $r['first_name'],
        $r['last_name'],
        $r['phone'],
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
      "SELECT A.first_name, A.last_name, A.phone, D.date_attended AS first_attendance_date
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
