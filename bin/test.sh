#!/usr/bin/env bash
# 运行插件测试：bin/test.sh [filter]
# 使用 Local 自带的 PHP + WP-CLI，连接 Local 站点数据库。路径可用环境变量覆盖。
set -euo pipefail
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WP_PATH="$(cd "$PLUGIN_DIR/../../.." && pwd)"
LOCAL_SUPPORT="$HOME/Library/Application Support/Local"
AP_PHP="${AP_PHP:-$LOCAL_SUPPORT/lightning-services/php-8.3.36+0/bin/darwin-arm64/bin/php}"
AP_SOCK="${AP_SOCK:-$LOCAL_SUPPORT/run/YeeJczQV4/mysql/mysqld.sock}"
AP_WPCLI="${AP_WPCLI:-/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/wp-cli.phar}"
exec "$AP_PHP" -d mysqli.default_socket="$AP_SOCK" -d pdo_mysql.default_socket="$AP_SOCK" -d memory_limit=1024M \
  "$AP_WPCLI" --path="$WP_PATH" eval-file "$PLUGIN_DIR/tests/run.php" "${1:-}"
