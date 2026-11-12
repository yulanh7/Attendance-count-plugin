<?php

namespace AP;

defined('ABSPATH') || exit;

class Attendance_DB
{
  /** 统一的签到结果结构 */
  private static function result(bool $ok, string $code, string $msg, array $extra = []): array
  {
    return array_merge([
      'ok'                    => $ok,
      'code'                  => $code,
      'msg'                   => $msg,
      'first_at_congregation' => false,
      'suggest_switch'        => false,
      'home_congregation'     => '',
      'existing_congregation' => null,
    ], $extra);
  }

  /** 电话号码归一化（与前端 normalizePhone 一致） */
  public static function normalize_phone(string $country_code, string $number): string
  {
    $number = preg_replace('/[\s\-\(\)]/', '', $number);
    // AU 本地格式：+61 去掉开头 0
    if ($country_code === '+61' && substr($number, 0, 1) === '0') {
      $number = substr($number, 1);
    }
    return $country_code . $number;
  }

  private static function find_person(string $phone): ?object
  {
    global $wpdb;
    if ($phone === '') return null;
    return $wpdb->get_row($wpdb->prepare(
      "SELECT id, first_name, last_name, home_congregation, first_attendance_date FROM {$wpdb->prefix}attendance WHERE phone = %s",
      $phone
    )) ?: null;
  }

  /** 今天的签到记录（一天最多一条，不分堂） */
  private static function today_record(int $aid, string $today): ?object
  {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
      "SELECT id, congregation FROM {$wpdb->prefix}attendance_dates WHERE attendance_id = %d AND date_attended = %s",
      $aid,
      $today
    )) ?: null;
  }

  /** 今天已经签过到 → 返回对应的结果；还没签 → null */
  private static function already_signed_result(int $aid, string $today, string $congregation): ?array
  {
    $rec = self::today_record($aid, $today);
    if (!$rec) return null;
    if ($rec->congregation === $congregation) {
      return self::result(false, 'already_signed', t('already_signed', $congregation));
    }
    $lang = Congregation::lang($congregation);
    return self::result(false, 'signed_elsewhere_today', t('signed_elsewhere_prompt', $congregation, [
      'existing' => Congregation::label($rec->congregation, $lang),
      'here'     => Congregation::label($congregation, $lang),
    ]), ['existing_congregation' => $rec->congregation]);
  }

  /**
   * 「第一次在这个堂签到」规则（今天的记录已写入之后调用）
   * - 除今天以外，这个堂没有他的出席记录 → 自动加入这个堂的新来宾名单
   * - 同时这个堂不是他的所属堂 → 建议转堂
   * @return array{0:bool,1:bool} [first_at_congregation, suggest_switch]
   */
  private static function apply_first_visit_rule(int $aid, string $congregation, string $home, string $today): array
  {
    global $wpdb;
    $before = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->prefix}attendance_dates WHERE attendance_id = %d AND congregation = %s AND date_attended <> %s",
      $aid,
      $congregation,
      $today
    ));
    if ($before > 0) return [false, false];

    $wpdb->query($wpdb->prepare(
      "INSERT IGNORE INTO {$wpdb->prefix}attendance_first_time_attendance_dates (attendance_id, date_attended, congregation) VALUES (%d, %s, %s)",
      $aid,
      $today,
      $congregation
    ));
    return [true, $home !== $congregation];
  }

  private static function insert_today(int $aid, string $today, string $congregation)
  {
    global $wpdb;
    return $wpdb->query($wpdb->prepare(
      "INSERT IGNORE INTO {$wpdb->prefix}attendance_dates (attendance_id, date_attended, congregation) VALUES (%d, %s, %s)",
      $aid,
      $today,
      $congregation
    ));
  }

  private static function welcome_msg(object $person, string $congregation, bool $first_at): string
  {
    $msg = t('check_in_ok_welcome', $congregation, ['name' => trim($person->first_name . ' ' . $person->last_name)]);
    if ($first_at) {
      $msg .= ' ' . t('welcome_first_time_here', $congregation, [
        'congregation' => Congregation::label($congregation, Congregation::lang($congregation)),
      ]);
    }
    return $msg;
  }

  /**
   * 首次登记表单提交
   * - 新号码：所属堂 = 当前签到页的堂；是否进新来宾名单由勾选框决定
   * - 已有号码：更新资料（不改所属堂），并套用「第一次在这个堂签到」规则
   * - 一天只有一条签到记录（不分堂）；今天已在别的堂签到 → signed_elsewhere_today，不写入任何东西
   */
  public static function handle_submit(array $post, string $congregation): array
  {
    global $wpdb;
    $attendance  = $wpdb->prefix . 'attendance';
    $first_dates = $wpdb->prefix . 'attendance_first_time_attendance_dates';

    if (!ap_is_check_in_day()) {
      return self::result(false, 'not_check_in_day', t('not_check_in_day', $congregation));
    }

    // 输入清洗 & 归一化
    $first_name      = trim(sanitize_text_field($post['es_first_name'] ?? ''));
    $last_name       = trim(sanitize_text_field($post['es_last_name'] ?? ''));
    $country_code    = trim(sanitize_text_field($post['es_phone_country_code'] ?? ''));
    $phone_number    = trim(sanitize_text_field($post['es_phone_number'] ?? ''));
    $fellowship      = trim(sanitize_text_field($post['es_fellowship'] ?? ''));
    $referral_source = trim(sanitize_text_field($post['es_referral_source'] ?? ''));
    $email           = sanitize_email($post['es_email'] ?? '');
    $is_newcomer     = isset($post['es_is_newcomer']) && (string) $post['es_is_newcomer'] === '1';
    $today           = ap_today();
    $phone           = self::normalize_phone($country_code, $phone_number);

    $existing = self::find_person($phone);
    if ($existing && ($signed = self::already_signed_result((int) $existing->id, $today, $congregation))) {
      return $signed;
    }

    // 原子 upsert（依赖 attendance.phone 唯一键）；所属堂只在新建时写入
    $ok = $wpdb->query($wpdb->prepare(
      "INSERT INTO $attendance
        (first_name, last_name, phone, email, fellowship, referral_source, is_new, first_attendance_date, home_congregation)
      VALUES (%s, %s, %s, %s, %s, %s, 1, %s, %s)
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
      $today,
      $congregation
    ));
    if ($ok === false) {
      return self::result(false, 'db_error', t('user_save_failed', $congregation));
    }

    $person = self::find_person($phone);
    if (!$person) {
      return self::result(false, 'db_error', t('user_save_failed_missing', $congregation));
    }
    $aid = (int) $person->id;

    $ins = self::insert_today($aid, $today, $congregation);
    if ($ins === false) {
      return self::result(false, 'db_error', t('check_in_failed', $congregation));
    }
    if ($ins === 0) {
      // 并发情况下另一请求先写入了今天的记录
      return self::already_signed_result($aid, $today, $congregation)
        ?? self::result(false, 'already_signed', t('already_signed', $congregation));
    }

    // 已写入新出勤：让 Dashboard 缓存失效
    self::clear_attendance_cache();

    $extra = ['home_congregation' => $person->home_congregation];

    if (!$existing) {
      // 全新登记：新来宾由勾选框决定
      $extra['first_at_congregation'] = true;
      if ($is_newcomer) {
        $wpdb->query($wpdb->prepare(
          "INSERT IGNORE INTO $first_dates (attendance_id, date_attended, congregation) VALUES (%d, %s, %s)",
          $aid,
          $today,
          $congregation
        ));
        return self::result(true, 'ok', t('check_in_ok_newcomer', $congregation), $extra);
      }
      return self::result(true, 'ok', t('check_in_ok', $congregation), $extra);
    }

    // 已有号码：勾选了新来宾 → 照旧直接登记为新来宾
    $msg = t('check_in_ok', $congregation);
    if ($is_newcomer) {
      $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO $first_dates (attendance_id, date_attended, congregation) VALUES (%d, %s, %s)",
        $aid,
        $today,
        $congregation
      ));
      $msg = t((int) $wpdb->rows_affected > 0 ? 'check_in_ok_newcomer_existing' : 'check_in_ok_newcomer_existing_dup', $congregation);
    }

    [$first_at, $suggest] = self::apply_first_visit_rule($aid, $congregation, $person->home_congregation, $today);
    $extra['first_at_congregation'] = $first_at;
    $extra['suggest_switch'] = $suggest;
    if ($first_at && !$is_newcomer) {
      $msg = self::welcome_msg($person, $congregation, true);
    }
    return self::result(true, 'ok', $msg, $extra);
  }

  /**
   * 快速签到（仅凭电话号码）
   */
  public static function quick_check_in(string $phone, string $congregation): array
  {
    if ($phone === '') {
      return self::result(false, 'phone_required', t('phone_required', $congregation));
    }
    $person = self::find_person($phone);
    if (!$person) {
      return self::result(false, 'not_registered', t('phone_not_registered', $congregation));
    }
    if (!ap_is_check_in_day()) {
      return self::result(false, 'not_check_in_day', t('not_check_in_day', $congregation));
    }

    $aid = (int) $person->id;
    $today = ap_today();
    if ($signed = self::already_signed_result($aid, $today, $congregation)) {
      return $signed;
    }

    $ins = self::insert_today($aid, $today, $congregation);
    if ($ins === false) {
      return self::result(false, 'db_error', t('check_in_failed', $congregation));
    }
    if ($ins === 0) {
      return self::already_signed_result($aid, $today, $congregation)
        ?? self::result(false, 'already_signed', t('already_signed', $congregation));
    }
    self::clear_attendance_cache();

    [$first_at, $suggest] = self::apply_first_visit_rule($aid, $congregation, $person->home_congregation, $today);
    return self::result(true, 'ok', self::welcome_msg($person, $congregation, $first_at), [
      'first_at_congregation' => $first_at,
      'suggest_switch'        => $suggest,
      'home_congregation'     => $person->home_congregation,
    ]);
  }

  /**
   * 把今天的签到记录移到另一个堂（同日换堂）
   * - 全新登记（first_attendance_date = 今天）：所属堂和今天的新来宾记录一起移过去
   * - 否则：删掉旧堂今天自动加的新来宾记录
   * - 然后按新堂重新套用「第一次在这个堂签到」规则
   */
  public static function move_today(string $phone, string $to): array
  {
    global $wpdb;
    $attendance  = $wpdb->prefix . 'attendance';
    $dates       = $wpdb->prefix . 'attendance_dates';
    $first_dates = $wpdb->prefix . 'attendance_first_time_attendance_dates';

    $person = self::find_person($phone);
    if (!$person) {
      return self::result(false, 'not_registered', t('phone_not_registered', $to));
    }
    if (!ap_is_check_in_day()) {
      return self::result(false, 'not_check_in_day', t('not_check_in_day', $to));
    }

    $aid = (int) $person->id;
    $today = ap_today();
    $rec = self::today_record($aid, $today);
    if (!$rec || $rec->congregation === $to) {
      return self::result(false, 'nothing_to_move', t('nothing_to_move', $to));
    }
    $from = $rec->congregation;

    $wpdb->update($dates, ['congregation' => $to], ['id' => (int) $rec->id], ['%s'], ['%d']);

    if ($person->first_attendance_date === $today) {
      $wpdb->update($attendance, ['home_congregation' => $to], ['id' => $aid], ['%s'], ['%d']);
      $wpdb->update($first_dates, ['congregation' => $to], ['attendance_id' => $aid, 'date_attended' => $today], ['%s'], ['%d', '%s']);
      $person->home_congregation = $to;
    } else {
      $wpdb->delete($first_dates, ['attendance_id' => $aid, 'date_attended' => $today, 'congregation' => $from], ['%d', '%s', '%s']);
    }
    self::clear_attendance_cache();

    [$first_at, $suggest] = self::apply_first_visit_rule($aid, $to, $person->home_congregation, $today);
    return self::result(true, 'ok', self::welcome_msg($person, $to, $first_at), [
      'first_at_congregation' => $first_at,
      'suggest_switch'        => $suggest,
      'home_congregation'     => $person->home_congregation,
    ]);
  }

  /** 常见国家代码：把完整号码拆回「国家代码 + 号码」 */
  private static function split_phone(string $full_phone): array
  {
    foreach (['+61', '+86', '+852', '+886', '+60', '+65'] as $code) {
      if (strpos($full_phone, $code) === 0) {
        return ['country_code' => $code, 'phone_number' => substr($full_phone, strlen($code))];
      }
    }
    return ['country_code' => '+61', 'phone_number' => $full_phone];
  }

  /**
   * 修改资料：按电话查资料（邮箱只返回打码版本）
   */
  public static function get_profile(string $phone): ?array
  {
    global $wpdb;
    if ($phone === '') return null;
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT first_name, last_name, phone, email, fellowship, home_congregation FROM {$wpdb->prefix}attendance WHERE phone = %s",
      $phone
    ));
    if (!$row) return null;
    return [
      'first_name'        => $row->first_name,
      'last_name'         => $row->last_name,
      'email_masked'      => ap_mask_email($row->email),
      'fellowship'        => $row->fellowship,
      'home_congregation' => $row->home_congregation,
    ] + self::split_phone($row->phone);
  }

  /**
   * 修改资料：可切换所属堂；团契必须属于所属堂（或 other），
   * 或者所属堂没变且团契也没变（保留原来别的堂的团契）
   */
  public static function update_profile(array $post, string $page_congregation): array
  {
    global $wpdb;
    $attendance = $wpdb->prefix . 'attendance';
    $c = $page_congregation;

    $first_name = trim(sanitize_text_field($post['es_first_name'] ?? ''));
    $last_name  = trim(sanitize_text_field($post['es_last_name'] ?? ''));
    $email      = sanitize_email($post['es_email'] ?? '');
    $fellowship = trim(sanitize_text_field($post['es_fellowship'] ?? ''));
    $phone      = self::normalize_phone(
      trim(sanitize_text_field($post['es_phone_country_code'] ?? '')),
      trim(sanitize_text_field($post['es_phone_number'] ?? ''))
    );

    if (!$first_name || !$last_name || !$fellowship || !$phone) {
      return ['ok' => false, 'code' => 'missing_fields', 'msg' => t('fields_required', $c)];
    }

    $current = $wpdb->get_row($wpdb->prepare(
      "SELECT id, fellowship, home_congregation FROM $attendance WHERE phone = %s",
      $phone
    ));
    if (!$current) {
      return ['ok' => false, 'code' => 'not_registered', 'msg' => t('phone_not_exist', $c)];
    }

    $home = $current->home_congregation;
    if (array_key_exists('es_home_congregation', $post)) {
      $home = Congregation::parse_strict(sanitize_text_field((string) $post['es_home_congregation']));
      if ($home === null) {
        return ['ok' => false, 'code' => 'invalid_congregation', 'msg' => t('invalid_congregation', $c)];
      }
    }

    $fellowship_ok = $fellowship === 'other'
      || Congregation::fellowship_owner($fellowship) === $home
      || ($home === $current->home_congregation && $fellowship === $current->fellowship);
    if (!$fellowship_ok) {
      return ['ok' => false, 'code' => 'fellowship_mismatch', 'msg' => t('fellowship_must_match_home', $c, [
        'congregation' => Congregation::label($home, Congregation::lang($c)),
      ])];
    }

    // 前台只看到打码邮箱，所以邮箱留空 = 不修改
    $data = [
      'first_name'        => $first_name,
      'last_name'         => $last_name,
      'fellowship'        => $fellowship,
      'home_congregation' => $home,
    ];
    if ($email !== '') {
      $data['email'] = $email;
    }
    $result = $wpdb->update($attendance, $data, ['id' => (int) $current->id], array_fill(0, count($data), '%s'), ['%d']);
    if ($result === false) {
      return ['ok' => false, 'code' => 'db_error', 'msg' => t('profile_update_failed', $c)];
    }

    self::clear_attendance_cache();
    return ['ok' => true, 'code' => 'ok', 'msg' => t('profile_update_ok', $c, ['name' => "{$first_name} {$last_name}"])];
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
