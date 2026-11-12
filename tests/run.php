<?php
/**
 * 极简测试运行器（通过 bin/test.sh → wp eval-file 执行）
 * - 每个 tests/test-*.php 返回 ['test_name' => callable, ...]
 * - 每个测试包在事务里并回滚；test_ddl_ 开头的不包事务
 */
require __DIR__ . '/helpers.php';

$filter = $args[0] ?? '';
global $wpdb;
$pass = 0;
$fail = 0;

foreach (glob(__DIR__ . '/test-*.php') as $file) {
  $tests = require $file;
  foreach ($tests as $name => $fn) {
    if ($filter !== '' && strpos($name, $filter) === false && strpos(basename($file), $filter) === false) {
      continue;
    }
    $tx = strpos($name, 'test_ddl_') !== 0;
    if ($tx) $wpdb->query('START TRANSACTION');
    try {
      $fn();
      $pass++;
      echo "PASS {$name}\n";
    } catch (\Throwable $e) {
      $fail++;
      echo "FAIL {$name}: " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    } finally {
      if ($tx) $wpdb->query('ROLLBACK');
      \AP\Tests\reset_state();
    }
  }
}

echo "{$pass} passed, {$fail} failed\n";
if ($fail > 0) exit(1);
