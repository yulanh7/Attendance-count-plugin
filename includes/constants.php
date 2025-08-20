<?php

namespace AP;

defined('ABSPATH') || exit;

// 周几允许打卡：0=Sun ... 6=Sat
function get_target_dow(): int
{
  return ((int) ES_ATTENDANCE_DOW) % 7;
}
