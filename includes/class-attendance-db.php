<?php

namespace AP;

defined('ABSPATH') || exit;

class Attendance_DB
{

  // 提交签到（前台）
  public static function handle_submit(array $post): array
  {
    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';
    $dates      = $wpdb->prefix . 'attendance_dates';

    // 仅允许目标星期签到
    if ((int) current_time('w') !== get_target_dow()) {
      return ['ok' => false, 'msg' => '今天不是允许的签到日，不能签到。'];
    }

    // —— 输入清洗 & 归一化 —— //
    $first_name   = trim(sanitize_text_field($post['es_first_name'] ?? ''));
    $last_name    = trim(sanitize_text_field($post['es_last_name'] ?? ''));
    $country_code = trim(sanitize_text_field($post['es_phone_country_code'] ?? ''));
    $phone_number = trim(sanitize_text_field($post['es_phone_number'] ?? ''));
    $fellowship   = trim(sanitize_text_field($post['es_fellowship'] ?? ''));
    $email        = sanitize_email($post['es_email'] ?? '');
    $today        = current_time('Y-m-d');

    // 去除空格/连字符/括号
    $phone_number = preg_replace('/[\s\-\(\)]/', '', $phone_number);

    // AU 本地格式：+61 去掉开头 0
    if ($country_code === '+61' && substr($phone_number, 0, 1) === '0') {
      $phone_number = substr($phone_number, 1);
    }
    $phone = $country_code . $phone_number;

    // —— 原子 upsert 人员（依赖 attendance.phone 唯一键）—— //
    // 首次插入 is_new=1，后续更新 is_new=0
    $upsert_sql = $wpdb->prepare(
      "INSERT INTO $attendance
      (first_name, last_name, phone, email, fellowship, is_new, first_attendance_date)
     VALUES (%s, %s, %s, %s, %s, 1, %s)
     ON DUPLICATE KEY UPDATE
       first_name = VALUES(first_name),
       last_name  = VALUES(last_name),
       email      = VALUES(email),
       fellowship = VALUES(fellowship),
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

    // 拿到人员 id（无论是新插入还是更新）
    $aid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $attendance WHERE phone = %s", $phone));
    if (!$aid) {
      return ['ok' => false, 'msg' => '创建/更新用户失败（未找到记录）。'];
    }

    // —— 写入当日出勤（依赖 dates 上的唯一键 (attendance_id, date_attended)）—— //
    // 原子去重：同人同日只保留一次
    $ins = $wpdb->query($wpdb->prepare(
      "INSERT IGNORE INTO $dates (attendance_id, date_attended) VALUES (%d, %s)",
      $aid,
      $today
    ));

    if ($ins === false) {
      return ['ok' => false, 'msg' => '记录签到失败，请稍后再试。'];
    }
    if ($ins === 0) {
      // 被唯一键忽略，说明今天已签到
      return ['ok' => false, 'msg' => '您今天已经签到过了，请勿重复签到!'];
    }

    return ['ok' => true, 'msg' => '签到成功！'];
  }


  // 后台筛选获取数据（返回数组，Admin_Page 负责渲染）
  public static function query_filtered(array $post): array
  {
    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';
    $dates      = $wpdb->prefix . 'attendance_dates';

    $q = "SELECT A.*
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
    $is_new = in_array(strtolower($raw_is_new), ['1', 'true', 'on', 'yes'], true);

    $pct_filter = (!empty($post['percentage_filter']) && $post['percentage_filter'] == 'true') ? 1 : 0;

    if ($fellowship) $q .= $wpdb->prepare(" AND fellowship = %s", $fellowship);
    if ($first_name) $q .= $wpdb->prepare(" AND first_name LIKE %s", '%' . $wpdb->esc_like($first_name) . '%');
    if ($last_name)  $q .= $wpdb->prepare(" AND last_name LIKE %s",  '%' . $wpdb->esc_like($last_name)  . '%');
    if ($phone)      $q .= $wpdb->prepare(" AND phone LIKE %s",      '%' . $wpdb->esc_like($phone)      . '%');
    if ($email)      $q .= $wpdb->prepare(" AND email LIKE %s",      '%' . $wpdb->esc_like($email)      . '%');

    if ($is_member) {
      $im = ($is_member === 'isMember') ? 1 : 0;
      $q .= $wpdb->prepare(" AND is_member = %d", $im);
    }

    $start_raw = sanitize_text_field($post['start_date_filter'] ?? '');
    $end_raw   = sanitize_text_field($post['end_date_filter'] ?? '');
    if ($start_raw) {
      $start = date('Y-m-d', strtotime($start_raw));
      $q .= $wpdb->prepare(" AND D.date_attended >= %s", $start);
    } else {
      $start = current_time('Y-m-d');
      $q .= $wpdb->prepare(" AND D.date_attended >= %s", $start);
    }
    if ($end_raw) {
      $end = date('Y-m-d', strtotime($end_raw));
      $q .= $wpdb->prepare(" AND D.date_attended <= %s", $end);
    } else {
      $end = current_time('Y-m-d');
      $q .= $wpdb->prepare(" AND D.date_attended <= %s", $end);
    }

    if ($is_new) {
      $q .= " AND A.id IN (
      SELECT attendance_id
      FROM $dates
      GROUP BY attendance_id
      HAVING COUNT(*) = 1
    )";
    }

    $rows = $wpdb->get_results($q, ARRAY_A);

    // 合并同手机号 & 计算比例（按 ES_ATTENDANCE_DOW）
    $rows = self::combine_by_phone($rows, $start, $end, $pct_filter);

    return $rows;
  }


  public static function get_last_attended_date(string $phone): ?string
  {
    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';
    $dates      = $wpdb->prefix . 'attendance_dates';
    return $wpdb->get_var($wpdb->prepare(
      "SELECT MAX(D.date_attended)
       FROM $attendance AS A INNER JOIN $dates AS D ON A.id = D.attendance_id
       WHERE A.phone = %s",
      $phone
    ));
  }

  public static function combine_by_phone(array $data, string $start_date, string $end_date, bool $pct_filter): array
  {
    $target = get_target_dow();
    $out = [];
    foreach ($data as $entry) {
      $phone = $entry['phone'];
      $new_start = new \DateTime($entry['first_attendance_date']);
      $format_start = new \DateTime($start_date);
      $effective_start = ($format_start < $new_start) ? $new_start : $format_start;

      $den = count_weekday_between($effective_start->format('Y-m-d'), $end_date, $target);

      if (!isset($out[$phone])) {
        $out[$phone] = $entry;
        $out[$phone]['times'] = 1;
        $out[$phone]['percentage'] = ($den <= 0) ? 0 : number_format(1 / $den * 100, 2, '.', '');
      } else {
        $out[$phone]['times']++;
        $out[$phone]['percentage'] = ($den <= 0) ? 0 : number_format($out[$phone]['times'] / $den * 100, 2, '.', '');
        if ($entry['id'] > $out[$phone]['id']) {
          $out[$phone]['first_name'] = $entry['first_name'];
          $out[$phone]['last_name']  = $entry['last_name'];
          $out[$phone]['phone']      = $entry['phone'];
          $out[$phone]['fellowship'] = $entry['fellowship'];
          $out[$phone]['is_new']     = $entry['is_new'];
        }
      }
      $last = self::get_last_attended_date($phone);
      $out[$phone]['last_attended'] = $last ? date('d/m/Y', strtotime($last)) : '';
    }

    $out = array_values($out);
    if ($pct_filter) {
      $out = array_filter($out, fn($r) => is_numeric($r['percentage']) && $r['percentage'] >= 50);
    }
    foreach ($out as $i => $row) $out[$i] = ['row_num' => $i + 1] + $row;
    return $out;
  }

  // 批量更新 member
  public static function bulk_update_member(array $post): string
  {
    global $wpdb;
    $ids = isset($post['ids']) ? (array)$post['ids'] : [];
    $action = sanitize_text_field($post['member_action'] ?? '');
    $is_member = ($action === 'make_member') ? 1 : 0;
    foreach ($ids as $id) {
      $wpdb->update($wpdb->prefix . 'attendance', ['is_member' => $is_member], ['id' => intval($id)], ['%d'], ['%d']);
    }
    return 'Member status updated successfully.';
  }

  // CSV 构建
  public static function build_csv(array $post): string
  {
    $rows = self::query_filtered($post);
    $lines = [implode(',', array_map('\AP\csv_escape', [
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
    ]))];

    foreach ($rows as $r) {
      $f = \AP\ap_translate_fellowship($r['fellowship']);

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

  // 详情（返回渲染用数据）
  public static function get_detail(int $attendance_id, string $start, string $end): array
  {
    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';
    $dates      = $wpdb->prefix . 'attendance_dates';

    $user = $wpdb->get_row($wpdb->prepare(
      "SELECT first_name,last_name,phone FROM $attendance WHERE id=%d",
      $attendance_id
    ));

    $list = $wpdb->get_results($wpdb->prepare(
      "SELECT date_attended FROM $dates WHERE attendance_id=%d AND date_attended BETWEEN %s AND %s",
      $attendance_id,
      $start,
      $end
    ), ARRAY_A);

    return ['user' => $user, 'list' => $list];
  }
}
