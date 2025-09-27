<?php

use function AP\get_target_dow;

$targetDow  = get_target_dow();
$todayDow   = (int) current_time('w');
$isAllowed  = ($todayDow === $targetDow);
$todayDate  = esc_html(current_time('d/m/Y'));
$weekdayMap = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$targetLabel = $weekdayMap[$targetDow];

$dateMessage = $isAllowed
  ? "Date: {$todayDate} ({$targetLabel})"
  : "<span style='color:#ef2723;font-size:18px'>今天不是 {$targetLabel}，不能签到。</span>";


$logo_url = esc_url('https://wp.canberra-ccc.org/wp-content/uploads/2023/08/church_logo_only.png');
?>

<div class="es-attendance-container">
  <!-- 默认签到表单 (只需电话) -->
  <form id="es_quick_attendance_form" class="es-attendance-form" style="display: block;">
    <img src="<?php echo $logo_url; ?>" alt="<?php echo esc_attr__('教会 Logo', 'attendance-plugin'); ?>" class="form-logo">
    <h3 class="form-title">快速签到</h3>
    <div class="form-body">
      <div class="phone-box">
        <select name="es_phone_country_code" required>
          <option value="+61" selected>+61</option>
          <option value="+86">+86</option>
          <option value="+852">+852</option>
          <option value="+886">+886</option>
          <option value="+60">+60</option>
          <option value="+65">+65</option>
        </select>
        <input type="text" name="es_phone_number" required placeholder="电话号码（必填）">
      </div>

      <div id="date-message"><?php echo $dateMessage; ?></div>
      <input type="submit" name="submit_attendance" value="签到" <?php echo $isAllowed ? '' : 'disabled'; ?>>
    </div>
    <div class="form-switch-buttons">
      <button type="button" id="switch-to-profile" class="switch-btn switch-btn-left">修改资料</button>
      <button type="button" id="switch-to-first-time" class="switch-btn switch-btn-right ">首次登记</button>
    </div>
  </form>

  <!-- 首次签到表单 (完整信息) -->
  <form id="es_first_time_form" class="es-attendance-form" style="display: none;">
    <img src="<?php echo $logo_url; ?>" alt="<?php echo esc_attr__('教会 Logo', 'attendance-plugin'); ?>" class="form-logo">
    <h3 class="form-title">首次登记</h3>

    <div class="form-body">
      <div class="es-field es-field--checkbox">
        <!-- hidden 先传 0，checkbox 覆盖为 1；避免未勾选时字段缺失 -->
        <input type="hidden" name="es_is_newcomer" value="0">
        <label class="es-checkbox">
          <input type="checkbox" name="es_is_newcomer" value="1" checked>
          <span><?php echo esc_html__('是否是新来宾', 'attendance-plugin'); ?></span>
        </label>
      </div>

      <input type="text" name="es_first_name" required placeholder="名字（必填）">
      <input type="text" name="es_last_name" required placeholder="姓氏（必填）">
      <input type="email" name="es_email" placeholder="邮箱（选填）">

      <div class="phone-box">
        <select name="es_phone_country_code" required>
          <option value="+61" selected>+61</option>
          <option value="+86">+86</option>
          <option value="+852">+852</option>
          <option value="+886">+886</option>
          <option value="+60">+60</option>
          <option value="+65">+65</option>
        </select>
        <input type="text" name="es_phone_number" required placeholder="电话号码（必填）">
      </div>

      <select name="es_fellowship" required>
        <option value="" disabled selected>您的团契（必选）</option>
        <option value="daniel">但以理团契</option>
        <option value="trueLove">真爱团团契</option>
        <option value="faithHopeLove">信望爱团契</option>
        <option value="peaceJoyPrayer">平安喜乐祷告会</option>
        <option value="other">其他</option>
      </select>

      <div id="date-message-first"><?php echo $dateMessage; ?></div>
      <input type="submit" name="submit_attendance" value="首次登记并签到" <?php echo $isAllowed ? '' : 'disabled'; ?>>
    </div>

    <div class="form-switch-buttons">
      <!-- <button type="button" id="first-to-profile" class="switch-btn switch-btn-left">修改资料</button> -->
      <button type="button" id="back-to-quick" class="switch-btn switch-btn-full">返回快速签到</button>
    </div>
  </form>

  <!-- 修改资料表单 (需输入电话查找用户) -->
  <form id="es_profile_form" class="es-attendance-form" style="display: none;">
    <img src="<?php echo $logo_url; ?>" alt="<?php echo esc_attr__('教会 Logo', 'attendance-plugin'); ?>" class="form-logo">
    <h3 class="form-title">修改资料</h3>
    <div class="form-body">

      <div class="phone-box">
        <select name="es_phone_country_code" required>
          <option value="+61" selected>+61</option>
          <option value="+86">+86</option>
          <option value="+852">+852</option>
          <option value="+886">+886</option>
          <option value="+60">+60</option>
          <option value="+65">+65</option>
        </select>
        <input type="text" name="es_phone_number" required placeholder="电话号码（必填）">
      </div>

      <div id="profile-user-info" style="display: none;">
        <input type="text" name="es_first_name" required placeholder="名字（必填）">
        <input type="text" name="es_last_name" required placeholder="姓氏（必填）">
        <input type="email" name="es_email" placeholder="邮箱（选填）">

        <select name="es_fellowship" required>
          <option value="" disabled>您的团契（必选）</option>
          <option value="daniel">但以理团契</option>
          <option value="trueLove">真爱团团契</option>
          <option value="faithHopeLove">信望爱团契</option>
          <option value="peaceJoyPrayer">平安喜乐祷告会</option>
          <option value="other">其他</option>
        </select>

        <div class="profile-action-row">
          <button type="button" id="profile-cancel-edit" style="display:none;margin-left:8px;">取消</button>
          <button type="button" id="profile-edit-other" style="display:none;margin-left:8px;">修改其他号码</button>
          <input type="submit" name="update_profile" value="更新资料">
        </div>
      </div>


      <div id="profile-check-section">
        <button type="button" id="check-profile-phone" class="profile-check-btn">查找资料</button>
      </div>
    </div>
    <div class="form-switch-buttons">
      <button type="button" id="profile-to-quick" class="switch-btn switch-btn-full">返回快速签到</button>
      <!-- <button type="button" id="profile-to-first" class="switch-btn switch-btn-right">首次登记</button> -->
    </div>
  </form>
</div>