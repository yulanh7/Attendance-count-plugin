<?php

namespace AP;

defined('ABSPATH') || exit;

/**
 * 当前登录账号能看到哪个堂
 * - 管理员（manage_options）：全部堂，可按堂筛选
 * - 其他账号：只能看到管理员在用户编辑页指定的「所属堂」（user meta ap_congregation）
 * - 范围一律在服务器端解析，绝不直接信任前端传来的堂
 */
class Access
{
  const USER_META = 'ap_congregation';
  private const NONCE = 'ap_user_congregation';

  public static function is_admin(): bool
  {
    return current_user_can('manage_options');
  }

  /** 账号被指定的堂；没指定或值无效 → null */
  public static function assigned(int $user_id = 0): ?string
  {
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id) return null;
    return Congregation::parse_strict((string) get_user_meta($user_id, self::USER_META, true));
  }

  public static function can_access(): bool
  {
    return is_user_logged_in() && (self::is_admin() || self::assigned() !== null);
  }

  /**
   * 解析本次请求的堂范围（调用前须确认 can_access()）
   * @return string|null null = 全部堂（仅管理员）
   */
  public static function scope(?string $requested = null): ?string
  {
    if (self::is_admin()) {
      return Congregation::parse_strict($requested);
    }
    return self::assigned();
  }

  /** 管理页使用的界面语言所对应的堂：管理员一律中文（国语堂） */
  public static function ui_congregation(): string
  {
    if (self::is_admin()) return Congregation::DEFAULT;
    return self::assigned() ?? Congregation::DEFAULT;
  }

  public static function register(): void
  {
    add_action('show_user_profile', [__CLASS__, 'render_user_field']);
    add_action('edit_user_profile', [__CLASS__, 'render_user_field']);
    add_action('personal_options_update', [__CLASS__, 'save_user_field']);
    add_action('edit_user_profile_update', [__CLASS__, 'save_user_field']);
  }

  /** 用户编辑页的「所属堂」下拉框（只有管理员看得到） */
  public static function render_user_field(\WP_User $user): void
  {
    if (!self::is_admin()) return;
    $current = self::assigned($user->ID);
    $lang = Congregation::lang(Congregation::DEFAULT);
    ?>
    <h2><?php echo esc_html(I18n::t('home_congregation_label', $lang)); ?>（签到系统）</h2>
    <table class="form-table" role="presentation">
      <tr>
        <th><label for="ap_congregation"><?php echo esc_html(I18n::t('home_congregation_label', $lang)); ?></label></th>
        <td>
          <?php wp_nonce_field(self::NONCE, '_ap_congregation_nonce'); ?>
          <select name="ap_congregation" id="ap_congregation">
            <option value="">未指定</option>
            <?php foreach (Congregation::all() as $c): ?>
              <option value="<?php echo esc_attr($c); ?>" <?php selected($current, $c); ?>><?php echo esc_html(Congregation::label($c, $lang)); ?></option>
            <?php endforeach; ?>
          </select>
          <p class="description">此账号在签到统计、新来宾名单页面只能看到所选堂的数据。管理员不受限制。</p>
        </td>
      </tr>
    </table>
    <?php
  }

  public static function save_user_field(int $user_id): void
  {
    if (!self::is_admin()) return;
    if (!isset($_POST['_ap_congregation_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ap_congregation_nonce'])), self::NONCE)) return;

    $c = Congregation::parse_strict(sanitize_text_field(wp_unslash($_POST['ap_congregation'] ?? '')));
    if ($c === null) {
      delete_user_meta($user_id, self::USER_META);
    } else {
      update_user_meta($user_id, self::USER_META, $c);
    }
  }
}
