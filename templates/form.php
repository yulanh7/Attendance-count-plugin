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
?>
<form id="es_attendance_form" class="es-attendance-form">
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
  <div id="date-message"><?php echo $dateMessage; ?></div>
  <input type="submit" name="submit_attendance" value="Submit Attendance" <?php echo $isAllowed ? '' : 'disabled'; ?>>
</form>