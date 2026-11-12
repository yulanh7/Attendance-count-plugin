<?php

/**
 * 签到表单（三合一：快速签到 / 首次登记 / 修改资料）
 * 由 ap_render_form_shortcode() 引入；可用变量：$congregation（已校验的堂代号）
 */

use AP\Congregation;
use function AP\get_target_dow;
use function AP\t;

defined('ABSPATH') || exit;

$c          = $congregation;
$lang       = Congregation::lang($c);
$targetDow  = get_target_dow();
$isAllowed  = \AP\ap_is_check_in_day();
$todayDate  = current_time('d/m/Y');

// 星期名称：国语堂沿用原来的英文写法
$weekdays = [
  'zh_CN' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
  'zh_HK' => ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'],
  'en'    => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
];
$targetLabel = $weekdays[$lang][$targetDow];

$dateMessage = $isAllowed
  ? esc_html(t('date_line', $c, ['date' => $todayDate, 'weekday' => $targetLabel]))
  : "<span style='color:#ef2723;font-size:18px'>" . esc_html(t('not_check_in_day_banner', $c, ['weekday' => $targetLabel])) . '</span>';

$logo_url = esc_url('https://wp.canberra-ccc.org/wp-content/uploads/2023/08/church_logo_only.png');
$country_codes = ['+61', '+86', '+852', '+886', '+60', '+65'];
$hidden_c = '<input type="hidden" name="congregation" value="' . esc_attr($c) . '">';

$phone_box = function () use ($country_codes, $c) {
  ob_start(); ?>
  <div class="phone-box">
    <select name="es_phone_country_code" required>
      <?php foreach ($country_codes as $i => $cc): ?>
        <option value="<?php echo esc_attr($cc); ?>" <?php echo $i === 0 ? 'selected' : ''; ?>><?php echo esc_html($cc); ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="es_phone_number" required placeholder="<?php echo esc_attr(t('phone_placeholder', $c)); ?>">
  </div>
<?php
  return ob_get_clean();
};
?>

<div class="es-attendance-container" data-congregation="<?php echo esc_attr($c); ?>">
  <!-- 默认签到表单 (只需电话) -->
  <form id="es_quick_attendance_form" class="es-attendance-form" style="display: block;">
    <?php echo $hidden_c; ?>
    <img src="<?php echo $logo_url; ?>" alt="<?php echo esc_attr(t('logo_alt', $c)); ?>" class="form-logo">
    <h3 class="form-title"><?php echo esc_html(t('quick_title', $c)); ?></h3>
    <div class="form-body">
      <?php echo $phone_box(); ?>

      <div id="date-message"><?php echo $dateMessage; ?></div>
      <input type="submit" name="submit_attendance" value="<?php echo esc_attr(t('submit_check_in', $c)); ?>" <?php echo $isAllowed ? '' : 'disabled'; ?>>
    </div>
    <div class="form-switch-buttons">
      <button type="button" id="switch-to-profile" class="switch-btn switch-btn-left"><?php echo esc_html(t('btn_edit_profile', $c)); ?></button>
      <button type="button" id="switch-to-first-time" class="switch-btn switch-btn-right "><?php echo esc_html(t('btn_first_time', $c)); ?></button>
    </div>
  </form>

  <!-- 首次签到表单 (完整信息) -->
  <form id="es_first_time_form" class="es-attendance-form" style="display: none;">
    <?php echo $hidden_c; ?>
    <img src="<?php echo $logo_url; ?>" alt="<?php echo esc_attr(t('logo_alt', $c)); ?>" class="form-logo">
    <h3 class="form-title"><?php echo esc_html(t('first_time_title', $c)); ?></h3>

    <div class="form-body">
      <div class="es-field es-field--checkbox">
        <!-- hidden 先传 0，checkbox 覆盖为 1；避免未勾选时字段缺失 -->
        <input type="hidden" name="es_is_newcomer" value="0">
        <label class="es-checkbox">
          <input type="checkbox" name="es_is_newcomer" value="1" checked>
          <span><?php echo esc_html(t('is_newcomer_label', $c)); ?></span>
        </label>
      </div>

      <input type="text" name="es_first_name" required placeholder="<?php echo esc_attr(t('first_name_placeholder', $c)); ?>">
      <input type="text" name="es_last_name" required placeholder="<?php echo esc_attr(t('last_name_placeholder', $c)); ?>">
      <input type="email" name="es_email" placeholder="<?php echo esc_attr(t('email_placeholder', $c)); ?>">

      <?php echo $phone_box(); ?>

      <input type="hidden" name="es_fellowship" value="other">

      <select name="es_referral_source" required>
        <option value="" disabled selected><?php echo esc_html(t('referral_placeholder', $c)); ?></option>
        <?php foreach (['family', 'friends', 'internet', 'walk_in', 'other'] as $src): ?>
          <option value="<?php echo esc_attr($src); ?>"><?php echo esc_html(t('referral_' . $src, $c)); ?></option>
        <?php endforeach; ?>
      </select>

      <div id="date-message-first"><?php echo $dateMessage; ?></div>
      <input type="submit" name="submit_attendance" value="<?php echo esc_attr(t('submit_first_time', $c)); ?>" <?php echo $isAllowed ? '' : 'disabled'; ?>>
    </div>

    <div class="form-switch-buttons">
      <button type="button" id="back-to-quick" class="switch-btn switch-btn-full"><?php echo esc_html(t('back_to_quick', $c)); ?></button>
    </div>
  </form>

  <!-- 修改资料表单 (需输入电话查找用户) -->
  <form id="es_profile_form" class="es-attendance-form" style="display: none;">
    <?php echo $hidden_c; ?>
    <img src="<?php echo $logo_url; ?>" alt="<?php echo esc_attr(t('logo_alt', $c)); ?>" class="form-logo">
    <h3 class="form-title"><?php echo esc_html(t('profile_title', $c)); ?></h3>
    <div class="form-body">

      <?php echo $phone_box(); ?>

      <div id="profile-user-info" style="display: none;">
        <input type="text" name="es_first_name" required placeholder="<?php echo esc_attr(t('first_name_placeholder', $c)); ?>">
        <input type="text" name="es_last_name" required placeholder="<?php echo esc_attr(t('last_name_placeholder', $c)); ?>">
        <input type="email" name="es_email" placeholder="<?php echo esc_attr(t('email_placeholder', $c)); ?>">

        <label class="es-field-label"><?php echo esc_html(t('home_congregation_label', $c)); ?></label>
        <select name="es_home_congregation" required>
          <?php foreach (Congregation::all() as $hc): ?>
            <option value="<?php echo esc_attr($hc); ?>" <?php selected($hc, $c); ?>><?php echo esc_html(Congregation::label($hc, $lang)); ?></option>
          <?php endforeach; ?>
        </select>

        <!-- 团契选项由 JS 按所属堂切换；首屏为本堂的团契 -->
        <select name="es_fellowship" required>
          <option value="" disabled><?php echo esc_html(t('fellowship_placeholder', $c)); ?></option>
          <?php foreach (Congregation::fellowships($c) as $key => $label): ?>
            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>

        <div class="profile-action-row">
          <button type="button" id="profile-cancel-edit" style="display:none;"><?php echo esc_html(t('btn_cancel', $c)); ?></button>
          <button type="button" id="profile-edit-other" style="display:none;"><?php echo esc_html(t('btn_edit_other_number', $c)); ?></button>
          <input type="submit" name="update_profile" value="<?php echo esc_attr(t('submit_update_profile', $c)); ?>">
        </div>
      </div>


      <div id="profile-check-section">
        <button type="button" id="check-profile-phone" class="profile-check-btn"><?php echo esc_html(t('btn_find_profile', $c)); ?></button>
      </div>
    </div>
    <div class="form-switch-buttons">
      <button type="button" id="profile-to-quick" class="switch-btn switch-btn-full"><?php echo esc_html(t('back_to_quick', $c)); ?></button>
    </div>
  </form>
</div>
