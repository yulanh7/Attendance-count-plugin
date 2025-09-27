<?php

declare(strict_types=1);

namespace AP;

defined('ABSPATH') || exit;

class Install
{
  /** 
   * 每次结构有变更时，更新这个版本号（用于 maybe_upgrade 判定）
   * 建议用日期版本，便于回顾
   */
  private const SCHEMA_VERSION = '2025.09.26';
  private const OPTION_KEY     = 'ap_attendance_schema_ver';

  /**
   * 激活：无条件跑一次表结构同步（dbDelta 幂等）+ 更新版本号
   */
  public static function activate(): void
  {
    self::create_or_update_tables();
    update_option(self::OPTION_KEY, self::SCHEMA_VERSION);
  }

  /**
   * 插件加载后，若发现版本落后，也同步一次（覆盖"只更新文件未重激活"的场景）
   */
  public static function maybe_upgrade(): void
  {
    $current = get_option(self::OPTION_KEY, '0');
    if (version_compare($current, self::SCHEMA_VERSION, '<')) {
      self::create_or_update_tables();
      update_option(self::OPTION_KEY, self::SCHEMA_VERSION);
    }
  }

  /**
   * 核心：创建/升级三张表（幂等）
   */
  private static function create_or_update_tables(): void
  {
    global $wpdb;

    $attendance  = $wpdb->prefix . 'attendance';
    $dates       = $wpdb->prefix . 'attendance_dates';
    $first_dates = $wpdb->prefix . 'attendance_first_time_attendance_dates';

    $charset = $wpdb->get_charset_collate();

    // 准备 dbDelta
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // 1) 主表：attendance
    // - phone 唯一
    // - 常用筛选列索引
    $sql_attendance = "CREATE TABLE {$attendance} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(100) NOT NULL,
            last_name  VARCHAR(100) NOT NULL,
            phone      VARCHAR(20)  NOT NULL,
            email      VARCHAR(255) DEFAULT '',
            fellowship VARCHAR(32)  NOT NULL,
            is_new     TINYINT(1)   NOT NULL DEFAULT 1,
            is_member  TINYINT(1)   NOT NULL DEFAULT 0,
            first_attendance_date DATE NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_phone (phone),
            KEY idx_fellowship (fellowship),
            KEY idx_is_member (is_member),
            KEY idx_first_attendance_date (first_attendance_date)
        ) {$charset};";

    // 2) 出席日期明细：attendance_dates
    // - (attendance_id, date_attended) 唯一，防止同人同日重复签到
    $sql_dates = "CREATE TABLE {$dates} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            attendance_id BIGINT(20) UNSIGNED NOT NULL,
            date_attended DATE NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_attendance_id (attendance_id),
            KEY idx_date_attended (date_attended),
            UNIQUE KEY uniq_attendance_date (attendance_id, date_attended)
        ) {$charset};";

    // 3) 首访日志表：attendance_first_time_attendance_dates
    // 要求与 attendance_dates 结构一致（含唯一键）
    $sql_first_dates = "CREATE TABLE {$first_dates} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            attendance_id BIGINT(20) UNSIGNED NOT NULL,
            date_attended DATE NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_attendance_id (attendance_id),
            KEY idx_date_attended (date_attended),
            UNIQUE KEY uniq_attendance_date (attendance_id, date_attended)
        ) {$charset};";

    // 分开执行更直观，也便于排错
    dbDelta($sql_attendance);
    dbDelta($sql_dates);
    dbDelta($sql_first_dates);

    // —— 兜底：如果历史版本没有 idx_first_attendance_date，则补上（INFO_SCHEMA 检查一次即可）
    self::ensure_index_first_attendance_date($attendance);
  }

  /**
   * 兜底补索引：attendance.first_attendance_date
   */
  private static function ensure_index_first_attendance_date(string $attendance_table): void
  {
    global $wpdb;

    // 某些旧站点可能缺这个索引，安全补齐一次
    $has_index = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT 1
                 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = %s
                   AND TABLE_NAME   = %s
                   AND INDEX_NAME   = 'idx_first_attendance_date'
                 LIMIT 1",
        DB_NAME,
        $attendance_table
      )
    );

    if (!$has_index) {
      // 不用 prepare（无用户输入），但保持最小化风险
      $wpdb->query("ALTER TABLE {$attendance_table} ADD KEY idx_first_attendance_date (first_attendance_date)");
    }
  }

  /**
   * 停用：此处不做任何破坏性动作
   */
  public static function deactivate(): void
  {
    // 留空：不在停用时删表，防止数据丢失
  }
}
