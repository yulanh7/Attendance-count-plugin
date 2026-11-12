# 多堂签到 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 让签到插件同时支持国语堂 / 粤语堂 / 英文堂：各堂独立签到页（固定语言、各自团契），各堂同工只能看到本堂数据，管理员看全部并可按堂筛选。

**Architecture:** 新增 `Congregation`（堂与团契注册表）、`I18n`（三语字符串表）、`Access`（按登录账号解析可见范围）三个小类；数据库给人员表加 `home_congregation`、给出席表和新来宾表加 `congregation`；所有查询 / 写入接口都接收一个由服务器端解析的「范围」参数（`?string`，`null` 表示全部堂），绝不直接信任前端传来的堂。前端 JS 的文案改为由 PHP 注入，并改用结构化的 `code` 字段判断结果，不再匹配中文子串。

**Tech Stack:** WordPress 插件（PHP 8.2+，jQuery，`$wpdb`），无新增依赖。测试用 WP-CLI `wp eval-file` 跑一个自带的极简测试运行器，连接 Local 站点数据库，每个测试包在事务里并回滚。

**Spec:** `docs/superpowers/specs/2026-11-12-multi-congregation-design.md`

## Global Constraints

- 堂代号固定为 `mandarin` / `cantonese` / `english`；shortcode 不带参数 = `mandarin`。
- 国语堂团契沿用现有代号与文字：`daniel` 但以理团契、`trueLove` 真爱团团契、`faithHopeLove` 信望爱团契、`peaceJoyPrayer` 平安喜乐祷告会、`other` 其他。
- 粤语堂团契文字（逐字）：五餅二魚團契、伊甸團契、長青團契、恩慈團契，外加「其他」。
- 英文堂团契文字（逐字）：Timothy Youth Fellowship、Bereans Bible Study、Southside Bible Study、New Mercies Fellowship、Northside Connect Group，外加 “Other”。
- 界面语言：国语堂简体中文；粤语堂繁体中文（香港用字）；英文堂英文；管理员（`manage_options`）的管理页一律简体中文。
- 同工账号的「所属堂」存 user meta `ap_congregation`；只有管理员能修改。
- 一个人每天只有一条签到记录，不分堂（保留唯一键 `(attendance_id, date_attended)`）；同一天在第二个堂签到时让他选择「改为这个堂」或「保留原来的堂」。
- 现有全部数据归 `mandarin`；迁移后国语堂 Dashboard 结果与迁移前逐行一致。
- 不做 WordPress 后台菜单；`attendance-plugin.php` 中被注释的 `admin_menu` 保持注释。
- 日期显示格式保持 `d/m/Y`。
- 每个 commit 前必须先给用户看 `git diff --stat` 和 commit 说明，得到确认后再提交（用户全局规则）。

## Review Focus

1. **Subscriber 篡改请求**（POST 里塞别的堂代号、别的堂的人员 id / 新来宾 id）→ 必须被忽略或拒绝，返回的只能是自己堂的数据。测试：Task 4 `test_scope_ignores_requested_for_subscriber`、Task 7 `test_bulk_update_rejects_out_of_scope_ids`、`test_detail_hides_other_congregation_dates`、Task 8 `test_delete_newcomer_rejects_other_congregation`。
2. **同一天在第二个堂再签到** → 先不写入任何东西，返回第一个堂的名字让他选择；选「改为」后今天仍然只有一条记录，旧堂今天自动加的新来宾记录被撤掉；连续切换两次（国语 → 粤语 → 国语）不会留下多余的新来宾记录。测试：Task 5 `test_second_check_in_same_day_other_congregation_asks`、`test_move_today_removes_auto_newcomer`、`test_move_twice_back_and_forth`。
3. **修改资料时团契和所属堂不匹配**（页面过期或被篡改）→ 拒绝保存。测试：Task 6 `test_update_rejects_fellowship_not_in_home`。
4. **没被指定堂的 Subscriber** 打开管理页 → 显示「尚未指定所属堂，请联系管理员」，接口返回 403。测试：Task 4 `test_unassigned_subscriber_cannot_access`、Task 9 `test_dashboard_unassigned_message`。
5. **shortcode 写错堂代号**（如 `congregation="Cantonese "`、`"cantonnese"`）→ 不能悄悄当成国语堂收签到；去掉首尾空格并转小写后仍无效的，签到页只显示错误提示，不显示表单。POST 里**带了**无效代号就拒绝；**没带**（旧版缓存的 JS）才按 `mandarin` 处理。测试：Task 1 `test_parse_strict`、Task 9 `test_form_invalid_congregation_shows_error`、Task 5 `test_check_in_rejects_invalid_congregation`。

---

## File Structure

| 文件 | 职责 |
|---|---|
| `includes/class-congregation.php`（新） | 堂与团契注册表：代号、显示名、语言、团契名单 |
| `includes/class-i18n.php`（新） | 三语字符串表 + `t()` |
| `includes/class-access.php`（新） | 当前用户能看哪个堂；用户编辑页「所属堂」字段 |
| `includes/class-install.php` | 表结构迁移 |
| `includes/class-attendance-db.php` | 签到写入、查询、导出，全部带范围参数 |
| `includes/class-ajax.php` | 接口：解析范围、调用 DB、返回 `code` |
| `includes/class-frontend-page.php`、`includes/class-admin-page.php`、`templates/form.php` | 渲染：按堂取团契、文字 |
| `includes/helpers.php` | 移除旧的 `ap_translate_fellowship`（改由 `Congregation`），权限 helper 接入 `Access` |
| `assets/js/main.js` | 文案改用注入的 `apI18n`，按 `code` 分支，转堂提示流程，管理员的堂筛选 |
| `tests/run.php`、`tests/helpers.php`、`tests/test-*.php`、`bin/test.sh`（新） | 测试运行器与测试 |

---

### Task 1: 测试运行器 + 堂注册表

**Files:**
- Create: `bin/test.sh`, `tests/run.php`, `tests/helpers.php`, `tests/test-congregation.php`, `includes/class-congregation.php`
- Modify: `attendance-plugin.php`（require 新文件）

**Interfaces:**
- Produces:
  - `bin/test.sh [filter]`：调用 Local 的 PHP 8.3 + WP-CLI 执行 `wp eval-file tests/run.php [filter]`。路径默认取本机 Local 的路径（PHP：`~/Library/Application Support/Local/lightning-services/php-8.3.36+0/bin/darwin-arm64/bin/php`；socket：`~/Library/Application Support/Local/run/YeeJczQV4/mysql/mysqld.sock`；WP-CLI：`/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/wp-cli.phar`；`--path` 指向站点的 `app/public`），可以用环境变量 `AP_PHP` / `AP_SOCK` / `AP_WPCLI` 覆盖。PHP 用 `-d mysqli.default_socket=… -d pdo_mysql.default_socket=…` 运行。
  - `tests/run.php`：加载 `tests/helpers.php` 和全部 `tests/test-*.php`。每个测试文件 `return ['test_name' => fn() => …, …]`。测试名包含 `[filter]` 才执行。每个测试前 `START TRANSACTION`，结束后 `ROLLBACK`；测试名以 `test_ddl_` 开头的不包事务。每个测试打印 `PASS name` / `FAIL name: message`，最后打印 `N passed, M failed`，有失败时 `exit(1)`。
  - `tests/helpers.php`（namespace `AP\Tests`）：`assert_same($expected, $actual, string $msg = ''): void`、`assert_true(bool $v, string $msg = ''): void`（失败时抛 `\Exception`）；夹具 `make_person(array $over = []): int`（电话用 `+999` 前缀加随机数，默认 `home_congregation='mandarin'`、`fellowship='other'`、`first_attendance_date='2026-01-04'`）、`add_attendance(int $id, string $ymd, string $congregation): void`、`add_newcomer(int $id, string $ymd, string $congregation): int`、`make_user(string $role, ?string $congregation): int`（并 `wp_set_current_user`）、`force_check_in_day(?string $today): void`（挂 `ap_is_check_in_day` → `true`，挂 `ap_today` → `$today`）。
  - `AP\Congregation`（静态类）：
    - `const MANDARIN = 'mandarin', CANTONESE = 'cantonese', ENGLISH = 'english'; const DEFAULT = self::MANDARIN;`
    - `all(): string[]` → 按上面顺序
    - `is_valid(string $c): bool`
    - `parse_strict(?string $raw): ?string` → 先 `trim` 再 `strtolower`，有效就返回代号，否则 `null`
    - `from_request(array $post, string $field = 'congregation'): string|false` → 字段不存在时返回 `DEFAULT`，存在但无效时返回 `false`
    - `lang(string $c): string` → `zh_CN` / `zh_HK` / `en`
    - `label(string $c, string $display_lang): string` → 堂名，用显示语言（zh_CN：国语堂 / 粤语堂 / 英文堂；zh_HK：國語堂 / 粵語堂 / 英文堂；en：Mandarin / Cantonese / English Congregation）
    - `fellowships(string $c): array<string,string>` → 代号 => 该堂语言的文字，`other` 放最后
    - `fellowship_label(string $key, string $display_lang): string` → 在所有堂里找；`other` 按显示语言（其他 / 其他 / Other）；找不到原样返回
    - `fellowship_owner(string $key): ?string` → `other` 返回 `null`
  - 粤语堂、英文堂的团契代号：`fiveLoavesTwoFish`、`eden`、`evergreen`、`mercy`；`timothyYouth`、`bereans`、`southside`、`newMercies`、`northsideConnect`。

- [ ] **Step 1: 写 `bin/test.sh`、`tests/run.php`、`tests/helpers.php`，再写失败的 `tests/test-congregation.php`**

```php
return [
  'test_all_codes' => fn() => assert_same(['mandarin','cantonese','english'], Congregation::all()),
  'test_parse_strict' => function () {
    assert_same('cantonese', Congregation::parse_strict(' Cantonese '));
    assert_same(null, Congregation::parse_strict('cantonnese'));
    assert_same(null, Congregation::parse_strict(''));
  },
  'test_from_request' => function () {
    assert_same('mandarin', Congregation::from_request([]));
    assert_same(false, Congregation::from_request(['congregation' => 'xx']));
    assert_same('english', Congregation::from_request(['congregation' => 'english']));
  },
  'test_fellowships_verbatim' => function () {
    assert_same(['fiveLoavesTwoFish'=>'五餅二魚團契','eden'=>'伊甸團契','evergreen'=>'長青團契','mercy'=>'恩慈團契','other'=>'其他'], Congregation::fellowships('cantonese'));
    assert_same('New Mercies Fellowship', Congregation::fellowships('english')['newMercies']);
    assert_same('但以理团契', Congregation::fellowships('mandarin')['daniel']);
  },
  'test_fellowship_owner_and_label' => function () {
    assert_same('cantonese', Congregation::fellowship_owner('eden'));
    assert_same(null, Congregation::fellowship_owner('other'));
    assert_same('Other', Congregation::fellowship_label('other', 'en'));
    assert_same('伊甸團契', Congregation::fellowship_label('eden', 'zh_CN'));
  },
];
```

- [ ] **Step 2: 运行 `bin/test.sh congregation`** → 预期 FAIL：`Class "AP\Congregation" not found`
- [ ] **Step 3: 实现 `includes/class-congregation.php`，并在 `attendance-plugin.php` 的 `constants.php` 之后 require**
- [ ] **Step 4: 运行 `bin/test.sh congregation`** → 预期 `5 passed, 0 failed`
- [ ] **Step 5: 给用户看 diff stat，确认后 commit** `test: add WP-CLI test runner; feat: add congregation registry`

---

### Task 2: 三语字符串表

**Files:**
- Create: `includes/class-i18n.php`, `tests/test-i18n.php`
- Modify: `attendance-plugin.php`（require）

**Interfaces:**
- Consumes: `Congregation::lang()`, `Congregation::label()`
- Produces:
  - `AP\I18n::t(string $key, string $lang, array $args = []): string` → 用 `strtr` 把 `{name}` 之类的占位符换成 `$args`；key 不存在时返回 key 本身
  - `AP\I18n::table(string $lang): array<string,string>`
  - `AP\I18n::js_keys(): string[]` → 需要注入到 JS 的 key
  - 全局函数 `AP\t(string $key, string $congregation, array $args = []): string` = `I18n::t($key, Congregation::lang($congregation), $args)`
- 字符串表要覆盖：`templates/form.php` 全部可见文字；`assets/js/main.js` 里全部可见中文（约 83 处，包括 `confirm()` / `alert()`）；`class-ajax.php`、`class-attendance-db.php` 返回给用户的消息；管理页（Dashboard、新来宾名单）上的文字。key 用英文 snake_case。zh_CN 的值必须逐字等于现有中文，保证国语堂界面不变。另外新增：
  - `signed_elsewhere_prompt` → zh_CN「您今天已在{existing}签到。要改为在{here}签到吗？」/ zh_HK「您今天已在{existing}簽到。要改為在{here}簽到嗎？」/ en “You have already checked in today at {existing}. Do you want to check in at {here} instead?”
  - `move_yes`（改为{here} / 改為{here} / Check in at {here}）、`move_no`（保留{existing} / 保留{existing} / Keep {existing}）
  - `welcome_first_time_here` → zh_CN「欢迎您第一次来到{congregation}！」/ zh_HK「歡迎您第一次來到{congregation}！」/ en “Welcome to {congregation} for the first time!”
  - `switch_prompt` → zh_HK「您目前登記在{home}。以後會固定在{here}聚會嗎？如果是，請選擇您的團契。」（zh_CN、en 对应翻译）
  - `switch_yes`（是，轉到{here}）、`switch_no`（不用，只是這次）
  - `seen_elsewhere`（曾在{congregation}出席）
  - `home_congregation_label`（所属堂 / 所屬堂 / Home congregation）
  - `unassigned_congregation`（尚未指定所属堂，请联系管理员）
  - `invalid_congregation_shortcode`（签到页设置错误：未知的堂代号）
  - `filter_all_congregations`（全部堂）、`column_congregation`（堂）
  - `fellowship_must_match_home`（请选择{congregation}的团契）

- [ ] **Step 1: 写失败的 `tests/test-i18n.php`**
  - `test_all_langs_have_same_keys`：`array_keys(table('zh_CN'))`、`zh_HK`、`en` 三者排序后相同
  - `test_no_empty_values`：三个表里没有空字符串
  - `test_placeholder`：`t('signed_elsewhere_prompt','cantonese',['existing'=>'國語堂','here'=>'粵語堂'])` === `您今天已在國語堂簽到。要改為在粵語堂簽到嗎？`
  - `test_js_keys_exist`：`js_keys()` 里每个 key 都在 `table('en')` 中
- [ ] **Step 2: 运行 `bin/test.sh i18n`** → 预期 FAIL：类不存在
- [ ] **Step 3: 实现 `includes/class-i18n.php`**（先用 `grep -nP "[\x{4e00}-\x{9fff}]"` 把 `form.php`、`main.js`、`class-ajax.php`、`class-attendance-db.php`、`class-frontend-page.php` 里的可见文字列成 key）
- [ ] **Step 4: 运行 `bin/test.sh i18n`** → 预期全部 PASS
- [ ] **Step 5: diff stat → 用户确认 → commit** `feat: add three-language string table`

---

### Task 3: 表结构迁移

**Files:**
- Modify: `includes/class-install.php`
- Create: `tests/test-install.php`

**Interfaces:**
- Produces（列名，后面的 task 都依赖）：
  - `{prefix}attendance.home_congregation VARCHAR(16) NOT NULL DEFAULT 'mandarin'`，`KEY idx_home_congregation (home_congregation)`
  - `{prefix}attendance_dates.congregation VARCHAR(16) NOT NULL DEFAULT 'mandarin'`，`KEY idx_congregation_date (congregation, date_attended)`
  - `{prefix}attendance_first_time_attendance_dates.congregation VARCHAR(16) NOT NULL DEFAULT 'mandarin'`，`KEY idx_congregation_date (congregation, date_attended)`
  - 新来宾表的唯一键 `uniq_attendance_date (attendance_id, date_attended)` 保持不变
  - `SCHEMA_VERSION = '2026.11.12'`
- 用 `dbDelta` 加列（`DEFAULT` 会让现有行自动填 `mandarin`）。保留现有 `ensure_index_first_attendance_date` 的写法，再给两个新索引各加一个同样风格的兜底检查。

- [ ] **Step 1: 写失败测试**
  - `test_ddl_columns_exist`：调用 `Install::activate()` 两次（验证幂等，第二次不能报错），然后 `SHOW COLUMNS` 确认三张表都有新列，默认值为 `mandarin`
  - `test_ddl_existing_rows_are_mandarin`：三张表里 `congregation`（或 `home_congregation`）`<> 'mandarin'` 的行数为 0（在迁移后、还没有新数据前跑）
- [ ] **Step 2: 运行 `bin/test.sh ddl_`** → 预期 FAIL：缺列
- [ ] **Step 3: 修改 `create_or_update_tables()` 的 3 段 SQL，补索引兜底，并提高 `SCHEMA_VERSION`**
- [ ] **Step 4: 运行 `bin/test.sh ddl_`** → PASS；再跑 `bin/test.sh` 确认 Task 1–2 仍然通过
- [ ] **Step 5: diff stat → 用户确认 → commit** `feat: add congregation columns (existing data -> mandarin)`

---

### Task 4: 访问范围（Access）+ 用户编辑页「所属堂」

**Files:**
- Create: `includes/class-access.php`, `tests/test-access.php`
- Modify: `includes/helpers.php`（`ap_user_can_view` / `ap_user_can_manage` 额外要求 `Access::can_access()`），`attendance-plugin.php`（require，并在 `plugins_loaded` 中调用 `Access::register()`）

**Interfaces:**
- Consumes: `Congregation`
- Produces:
  - `AP\Access::USER_META = 'ap_congregation'`
  - `is_admin(): bool` → `current_user_can('manage_options')`
  - `assigned(int $user_id = 0): ?string` → 读 user meta（0 = 当前用户），经过 `parse_strict`
  - `can_access(): bool` → 已登录，并且（是管理员，或者 `assigned()` 不为 null）
  - `scope(?string $requested = null): ?string` → 管理员：`parse_strict($requested)`（null 表示全部堂）；非管理员：一律返回 `assigned()`，忽略 `$requested`。调用前必须先确认 `can_access()` 为真。
  - `ui_congregation(): string` → 管理员返回 `mandarin`，其他人返回 `assigned()`
  - `register(): void` → 挂 `show_user_profile`、`edit_user_profile`（只有当前用户是管理员时才渲染下拉框，选项是「未指定」和三个堂）；挂 `personal_options_update`、`edit_user_profile_update`（只有管理员能保存，校验 nonce，值无效时删除 meta）

- [ ] **Step 1: 写失败测试**
  - `test_admin_scope_all_and_filter`：管理员调用 `scope(null)` 得到 `null`，`scope('english')` 得到 `'english'`，`scope('bad')` 得到 `null`
  - `test_scope_ignores_requested_for_subscriber`：指定为 cantonese 的 Subscriber 调用 `scope('mandarin')` 得到 `'cantonese'`
  - `test_unassigned_subscriber_cannot_access`：`can_access()` 为 false，`ap_user_can_view()` 为 false
  - `test_ui_congregation`：管理员得到 `mandarin`，英文堂 Subscriber 得到 `english`
- [ ] **Step 2: 运行 `bin/test.sh access`** → FAIL
- [ ] **Step 3: 实现 `class-access.php`，并修改 helpers**
- [ ] **Step 4: 运行 `bin/test.sh`** → 全部 PASS
- [ ] **Step 5: 手动检查**：后台用户编辑页，管理员能看到并保存「所属堂」；用 Subscriber 编辑自己的资料时看不到这个字段
- [ ] **Step 6: diff stat → 用户确认 → commit** `feat: per-user congregation assignment and access scope`

---

### Task 5: 签到写入（首次登记 + 快速签到）

**Files:**
- Modify: `includes/class-attendance-db.php`（`handle_submit`，新增 `quick_check_in`），`includes/class-ajax.php`（`handle_attendance`、`quick_attendance` 改为调用 DB 方法并透传结果）
- Create: `tests/test-check-in.php`

**Interfaces:**
- Consumes: `Congregation::from_request`, `t()`, Task 3 的列
- Produces:
  - 签到日判断统一改成 `apply_filters('ap_is_check_in_day', (int) current_time('w') === get_target_dow())`；日期统一改成 `ap_today(): string` = `apply_filters('ap_today', current_time('Y-m-d'))`（加在 `helpers.php`）
  - `Attendance_DB::handle_submit(array $post, string $congregation): array`
  - `Attendance_DB::quick_check_in(string $phone, string $congregation): array`
  - `Attendance_DB::move_today(string $phone, string $to_congregation): array` → 把今天的签到记录移到 `$to_congregation`
  - 三者的返回结构相同：`['ok'=>bool, 'code'=>string, 'msg'=>string, 'first_at_congregation'=>bool, 'suggest_switch'=>bool, 'home_congregation'=>string, 'existing_congregation'=>?string]`
    - `code` 取值：`ok`、`not_check_in_day`、`not_registered`、`already_signed`（今天已在**同一个**堂签过）、`signed_elsewhere_today`（今天已在**另一个**堂签过，`existing_congregation` 为那个堂，什么都没写入）、`nothing_to_move`（`move_today` 时今天没有记录，或已经在目标堂）、`db_error`
    - `msg` 用 `$congregation` 对应的语言
  - 首次登记（新号码）：`home_congregation = $congregation`；出席记录和新来宾记录的 `congregation = $congregation`；是否写新来宾记录仍然由勾选框决定（现有逻辑不变）
  - 已存在的人在某个堂签到成功，并且这个堂之前没有他的出席记录时：设 `first_at_congregation=true`，自动写一条新来宾记录（`INSERT IGNORE`，堂 = 当前堂）；如果 `home_congregation !== $congregation`，再设 `suggest_switch=true`；`msg` 在成功文字后加上 `welcome_first_time_here`
  - 今天已在同一个堂签到：`code='already_signed'`（沿用现有文字）
  - 今天已在另一个堂签到：`code='signed_elsewhere_today'`，`msg = t('signed_elsewhere_prompt', $congregation, ['existing' => 那个堂的名字, 'here' => 这个堂的名字])`；不写入任何东西
  - `move_today` 的步骤（放在一个事务里）：
    1. 把今天的出席记录改成 `$to_congregation`
    2. 如果 `first_attendance_date` = 今天（全新登记）：`home_congregation` 改成 `$to_congregation`，今天的新来宾记录（如果有）也改成 `$to_congregation`
    3. 否则：删掉旧堂今天的新来宾记录
    4. 按 `$to_congregation` 重新套用"第一次在这个堂"的规则（判断时排除今天这条记录本身），得出 `first_at_congregation`、`suggest_switch`，需要时自动加新来宾记录
    5. 清缓存
  - Ajax 新增 `es_move_attendance`（`wp_ajax` 和 `wp_ajax_nopriv` 都挂，校验 nonce，参数是 `phone` 和 `congregation`，同样要检查是否签到日）
  - Ajax：`$c = Congregation::from_request($_POST)`；为 `false` 时返回 `wp_send_json_error(['code'=>'invalid_congregation'], 400)`；成功时 `wp_send_json_success($res)`，失败时 `wp_send_json_error($res)`
  - 每次成功写入后照常调用 `clear_attendance_cache()`

- [ ] **Step 1: 写失败测试**（每个测试都先 `force_check_in_day('2026-11-15')`）
  - `test_new_person_home_is_page_congregation`：在 cantonese 页首次登记，人员的 `home_congregation='cantonese'`，出席记录的 `congregation='cantonese'`
  - `test_existing_person_first_time_other_congregation`：mandarin 的人以前有 mandarin 出席，这次 `quick_check_in` 在 cantonese 签到：`ok`，`first_at_congregation=true`，`suggest_switch=true`，并且有一条 cantonese 的新来宾记录
  - `test_second_visit_same_congregation_no_prompt`：同一个人下周（`ap_today` 设为 `'2026-11-22'`）又在 cantonese 签到：`first_at_congregation=false`，`suggest_switch=false`，新来宾记录还是只有 1 条
  - `test_first_time_at_home_congregation_no_switch`：home 是 cantonese 但从没在 cantonese 签到过，第一次在 cantonese 签到：`first_at_congregation=true`，`suggest_switch=false`
  - `test_second_check_in_same_day_other_congregation_asks`：同一天先在 mandarin、再在 cantonese 签到，第二次返回 `code='signed_elsewhere_today'`，`existing_congregation='mandarin'`，`msg` 包含 `國語堂`；今天的出席记录仍是 mandarin，cantonese 的新来宾记录数为 0
  - `test_move_today_removes_auto_newcomer`：mandarin 的老会友今天第一次去 cantonese（自动加了 cantonese 新来宾），然后 `move_today(…, 'mandarin')`：今天的记录变成 mandarin，cantonese 今天的新来宾记录为 0，返回 `suggest_switch=false`
  - `test_move_today_brand_new_person`：今天在 mandarin 全新登记并勾选新来宾，`move_today(…, 'cantonese')`：`home_congregation='cantonese'`，新来宾记录在 cantonese，mandarin 的为 0
  - `test_move_twice_back_and_forth`：老会友 mandarin → 签到 cantonese（自动新来宾）→ 移回 mandarin → 再移到 cantonese：今天出席记录 1 条（cantonese），cantonese 新来宾记录 1 条，`suggest_switch=true`
  - `test_move_today_nothing_to_move`：今天没签到时返回 `code='nothing_to_move'`
  - `test_new_registration_checkbox_unchecked_no_newcomer`：新号码登记时没勾选新来宾，新来宾记录数为 0
  - `test_check_in_rejects_invalid_congregation`：`Congregation::from_request(['congregation'=>'xx'])` 为 `false`（Ajax 层据此返回 400）
  - `test_not_check_in_day`：`ap_is_check_in_day` 返回 false 时，`code='not_check_in_day'`
- [ ] **Step 2: 运行 `bin/test.sh check_in`** → FAIL
- [ ] **Step 3: 实现**
- [ ] **Step 4: 运行 `bin/test.sh`** → 全部 PASS
- [ ] **Step 5: diff stat → 用户确认 → commit** `feat: congregation-aware check-in with auto newcomer and switch suggestion`

---

### Task 6: 修改资料（所属堂 + 团契校验）

**Files:**
- Modify: `includes/class-ajax.php`（`get_user_profile`、`update_profile`；把查询和更新逻辑移进 DB 类）、`includes/class-attendance-db.php`
- Create: `tests/test-profile.php`

**Interfaces:**
- Produces:
  - `Attendance_DB::get_profile(string $phone): ?array` → 字段：`first_name`、`last_name`、`email_masked`、`fellowship`、`home_congregation`、`country_code`、`phone_number`
  - `Attendance_DB::update_profile(array $post, string $page_congregation): array` → 返回 `['ok'=>bool,'code'=>string,'msg'=>string]`；`code` 取值：`ok`、`missing_fields`、`not_registered`、`fellowship_mismatch`、`invalid_congregation`、`db_error`
  - 新增 POST 字段 `es_home_congregation`：没传时保留原值；传了但无效 → `invalid_congregation`
  - 校验规则：设 `home` 为新的所属堂。`fellowship` 必须满足以下之一：属于 `home` 的团契，或者是 `other`，或者**等于原来存的团契并且所属堂没变**。不满足 → `fellowship_mismatch`，`msg = t('fellowship_must_match_home', $page_congregation, ['congregation' => label(home)])`
  - 邮箱留空就不修改（保留现有行为）

- [ ] **Step 1: 写失败测试**
  - `test_get_profile_includes_home`
  - `test_switch_home_with_matching_fellowship`：mandarin/daniel 的人改成 cantonese/eden → `ok`，数据库里变成 cantonese/eden
  - `test_update_rejects_fellowship_not_in_home`：改成 cantonese 但团契仍是 daniel → `fellowship_mismatch`，数据库不变
  - `test_keep_cross_congregation_fellowship_when_home_unchanged`：home 是 cantonese、团契是 daniel，不改 home 只改名字 → `ok`
  - `test_invalid_home_rejected`
- [ ] **Step 2: 运行 `bin/test.sh profile`** → FAIL
- [ ] **Step 3: 实现**
- [ ] **Step 4: 运行 `bin/test.sh`** → 全部 PASS
- [ ] **Step 5: diff stat → 用户确认 → commit** `feat: home congregation in profile with fellowship validation`

---

### Task 7: Dashboard 查询、导出、详情、批量会员按堂限定

**Files:**
- Modify: `includes/class-attendance-db.php`（`query_filtered`、`decorate_rows`、`build_csv`、`get_detail`、`bulk_update_member`），`includes/class-ajax.php`（`filter_attendance`、`export_csv`、`get_attendance_info`、`update_member`：先 `Access::scope($_POST['congregation'] ?? null)`，再把结果传给 DB）
- Create: `tests/test-dashboard.php`

**Interfaces:**
- Consumes: `Access::scope()`、`Access::is_admin()`、Task 3 的列
- Produces：
  - `query_filtered(array $post, ?string $congregation): array`
    - 子查询 R（区间内次数）在 `$congregation !== null` 时加 `AND congregation = %s`
    - `last_attended` 在同样的范围内取最大值
    - `times_all`（用于判断 is_new）保持全历史、不分堂
    - 分母沿用 `first_attendance_date`
    - 新增 `$post['fellowship']` 筛选（沿用现有）
    - 缓存键里加入 `$congregation ?? 'all'`
    - 当 `$congregation === null` 时，每行多一个字段 `congregation_counts: array<string,int>`（区间内每个堂的次数，用一条 `GROUP BY attendance_id, congregation` 查询再在 PHP 里合并）
  - `build_csv(array $post, ?string $congregation, bool $with_congregation_column): string` → 管理员才有「堂」列；`$congregation === null` 时这一列显示「国语 3、粤语 1」这样的分布，否则显示堂名
  - `get_detail(int $id, string $start, string $end, ?string $congregation): array` → `list` 里每行加 `congregation`；有范围时只返回该堂的日期
  - `bulk_update_member(array $post, ?string $congregation): string` → `$congregation !== null` 时，只更新在该堂有出席记录的 id，其余 id 静默跳过
  - 团契显示统一改用 `Congregation::fellowship_label($key, $display_lang)`（`$display_lang` = `Congregation::lang(Access::ui_congregation())`），删除 `ap_translate_fellowship` 及所有调用它的地方

- [ ] **Step 1: 写失败测试**（夹具：A 在 4 个主日里 2 次 mandarin、2 次 cantonese；B 只去 mandarin）
  - `test_percentage_per_congregation_and_all`：A 在 mandarin = `50.00`，cantonese = `50.00`，全部 = `100.00`
  - `test_subscriber_scope_excludes_other_congregation_people`：cantonese 范围的结果里没有 B
  - `test_all_scope_has_congregation_counts`：A 的 `congregation_counts` === `['mandarin'=>2,'cantonese'=>2]`
  - `test_cache_separated_by_scope`：同样的 `$post` 先查 mandarin、再查 cantonese，两次结果不同
  - `test_bulk_update_rejects_out_of_scope_ids`：cantonese 范围下对 B 设会员，B 的 `is_member` 不变
  - `test_detail_hides_other_congregation_dates`：cantonese 范围下 A 的详情只有 2 条
  - `test_csv_congregation_column_admin_only`：`$with_congregation_column=false` 时表头里没有「堂」
- [ ] **Step 2: 运行 `bin/test.sh dashboard`** → FAIL
- [ ] **Step 3: 实现**
- [ ] **Step 4: 运行 `bin/test.sh`** → 全部 PASS
- [ ] **Step 5: diff stat → 用户确认 → commit** `feat: scope dashboard queries, export, detail and bulk update by congregation`

---

### Task 8: 新来宾 / 首访名单按堂限定

**Files:**
- Modify: `includes/class-attendance-db.php`（`query_first_timers_log`，新增 `query_first_timers` 和 `delete_newcomer`），`includes/class-ajax.php`（`newcomers_query`、`newcomers_export`、`first_timers_log_*`、`first_timers_query`、`first_timers_export`、`handle_delete_newcomer`：都先经过 `Access::can_access()` / `scope()`）
- Create: `tests/test-newcomers.php`

**Interfaces:**
- Produces:
  - `query_first_timers_log(string $start, string $end, ?string $congregation): array` → 每行加 `congregation` 和 `seen_elsewhere: ?string`（这个人在本条记录日期**之前**最早一次在**其他**堂出席的堂代号，没有就是 `null`）；两个日期参数先用 `DateTime::createFromFormat('Y-m-d')` 校验，无效就用今天
  - `query_first_timers(string $start, string $end, ?string $congregation): array` → 原来 `first_timers_query`、`first_timers_export` 和首访 shortcode 首屏里的 SQL 合并到这里；有范围时，按这个人第一条出席记录所在的堂来判断归属
  - `delete_newcomer(int $id, ?string $congregation): bool` → 有范围时，记录的堂必须相同，否则返回 false（Ajax 返回 403）
  - `Frontend_Page::render_first_timers_list_html(array $rows, bool $can_view_phone, bool $deletable, bool $show_referral_source, string $display_lang, bool $show_congregation): string` → 卡片上 `seen_elsewhere` 不为空时，加一行 `t('seen_elsewhere', …)`；`$show_congregation`（仅管理员）时显示堂名

- [ ] **Step 1: 写失败测试**
  - `test_newcomer_scope`：mandarin 和 cantonese 各有一条新来宾记录，cantonese 范围只返回 1 条
  - `test_seen_elsewhere`：A 以前去过 mandarin，后来自动成为 cantonese 新来宾，那条记录的 `seen_elsewhere === 'mandarin'`；全新的人为 `null`
  - `test_delete_newcomer_rejects_other_congregation`：cantonese 范围删除 mandarin 的记录 → false，记录仍在
  - `test_first_timers_scope_by_first_attendance`
- [ ] **Step 2: 运行 `bin/test.sh newcomer`、`bin/test.sh first_timers`** → FAIL
- [ ] **Step 3: 实现**
- [ ] **Step 4: 运行 `bin/test.sh`** → 全部 PASS
- [ ] **Step 5: diff stat → 用户确认 → commit** `feat: scope newcomer and first-timer lists by congregation`

---

### Task 9: 前台渲染（签到页 + 管理页）与 JS

**Files:**
- Modify: `templates/form.php`、`includes/helpers.php`（`ap_render_form_shortcode($atts)`）、`includes/class-frontend-page.php`、`includes/class-admin-page.php`（`render_table_simple` 增加堂列）、`assets/js/main.js`
- Create: `tests/test-render.php`

**Interfaces:**
- Consumes: Task 1–8 的全部接口
- Produces:
  - `[attendance_form congregation="…"]`：用 `Congregation::parse_strict`；没写参数就是 `mandarin`；写了但无效时只输出 `t('invalid_congregation_shortcode', 'mandarin')`，不输出表单
  - 表单里加隐藏字段 `<input type="hidden" name="congregation" value="{c}">`，三个表单（快速签到、首次登记、修改资料）都要加
  - 团契 `<select>` 由 `Congregation::fellowships()` 生成
  - 修改资料表单加 `<select name="es_home_congregation">`，选项是三个堂，用页面语言显示
  - `AP\provide_js_strings(string $congregation): void` → 只调用一次：`wp_add_inline_script('ap-main', 'window.apI18n = {...}; window.apCtx = {...};', 'before')`
    - `apI18n` 内容是 `I18n::js_keys()` 对应的字符串
    - `apCtx = {congregation, isAdmin, fellowships: {mandarin: {...}, cantonese: {...}, english: {...}}, labels: {堂代号: 堂名}}`
    - 签到页按 shortcode 的堂调用；管理页按 `Access::ui_congregation()` 调用
  - 管理页 shortcode（`attendance_dashboard`、`attendance_newcomers`、`attendance_first_timers`）：
    - 未登录：沿用现有处理
    - 已登录但 `!Access::can_access()`：只输出 `t('unassigned_congregation', 'mandarin')`
    - 管理员：多一个 `<select id="fe_congregation_filter">`（全部堂 + 三个堂）；团契筛选的选项随堂切换（选全部堂时，按堂分组列出全部团契）
    - Subscriber：只显示本堂的团契，没有堂筛选，也没有堂列
  - JS：
    - 所有可见文字改成 `T(key, args)`（读 `window.apI18n`，替换 `{占位符}`）
    - 签到请求一律带上 `congregation: apCtx.congregation`
    - 删除所有按中文子串（如 `includes("欢迎")`、`includes("已经签到")`）判断结果的代码，改成按 `resp.data.code` 分支
    - 管理页的 AJAX 请求带上 `congregation`（仅管理员有这个筛选）
    - 新来宾卡片的 JS 渲染分支显示 `seen_elsewhere`
  - 同日换堂流程（JS）：签到返回 `code === 'signed_elsewhere_today'` 时，用和转堂提示同样的提示块显示 `msg` 和两个按钮（`move_yes`、`move_no`）。点「保留」：移除提示块。点「改为」：调用 `es_move_attendance`，成功后按普通签到成功处理（包括可能出现的转堂提示）。
  - 转堂流程（JS）：签到成功并且 `resp.data.suggest_switch` 时，在成功信息下方插入提示块（`switch_prompt` + 两个按钮），**不用** `confirm()`。点「不用」：移除提示块。点「是」：切到修改资料表单，填入电话，调用 `loadUserProfileForEdit`，完成后把 `es_home_congregation` 设为 `apCtx.congregation`，团契下拉框换成该堂的选项并清空选择（`required`）
  - 修改资料表单里，`es_home_congregation` 变化时，团契下拉框换成新堂的选项并清空。如果原来的团契属于别的堂并且所属堂没变，下拉框里额外显示这个选项，文字为「团契名（堂名）」

- [ ] **Step 1: 写失败测试 `tests/test-render.php`**（直接调用 shortcode 函数，检查返回的 HTML）
  - `test_form_cantonese_fellowships_only`：`do_shortcode('[attendance_form congregation="cantonese"]')` 包含 `伊甸團契` 和 `name="congregation" value="cantonese"`，不包含 `但以理团契`、`Bereans`
  - `test_form_default_mandarin`：不带参数时包含 `value="mandarin"` 和 `快速签到`
  - `test_form_invalid_congregation_shows_error`：`congregation="cantonnese"` 时不包含 `<form`
  - `test_dashboard_unassigned_message`：未指定堂的 Subscriber 只看到 unassigned 文字，没有 `<table`
  - `test_dashboard_admin_has_congregation_filter`、`test_dashboard_subscriber_no_congregation_filter`
- [ ] **Step 2: 运行 `bin/test.sh render`** → FAIL
- [ ] **Step 3: 实现 PHP 渲染部分**
- [ ] **Step 4: 运行 `bin/test.sh`** → 全部 PASS
- [ ] **Step 5: 实现 JS 部分，然后 `node --check assets/js/main.js`** → 没有输出
- [ ] **Step 6: 浏览器手动验证**（在 `http://cccc.local` 建 3 个签到页、1 个 Dashboard 页、1 个新来宾页，用 `apply_filters('ap_is_check_in_day')` 临时放开签到日）：
  1. 粤语堂签到页全部是繁体，英文堂签到页全部是英文，国语堂签到页和改动前一模一样
  2. 国语堂的老会友到粤语堂快速签到：看到欢迎语和转堂提示；点「不用」后提示消失；下周再签到不再出现提示
  3. 点「是」：切换到修改资料，所属堂已经是粤语堂，团契必须重新选，保存成功
  4. 同一天去另一个堂签到：出现「要改为在…签到吗？」。选保留，什么都不变；选改为，Dashboard 里今天的记录出现在新的堂，旧堂的新来宾名单里不再有他
  5. 粤语堂 Subscriber 打开 Dashboard 和新来宾页：只有粤语堂的数据、繁体文字，没有堂列；管理员能看到堂筛选、堂列和「曾在…出席」
  6. 管理员导出 CSV 有「堂」列，Subscriber 导出的没有
- [ ] **Step 7: diff stat → 用户确认 → commit** `feat: congregation-aware forms, dashboards and JS strings`

---

### Task 10: 迁移对比验证 + 发版

**Files:**
- Create: `tests/compare-mandarin.php`（不算单元测试，手动运行）
- Modify: `attendance-plugin.php`（`Version: 2.6.0`）

- [ ] **Step 1: 写 `tests/compare-mandarin.php`**：用 `git show 2561b4e:includes/class-attendance-db.php` 取出多堂改动之前的版本，把类名改成 `Attendance_DB_Old` 后加载（它的 `query_filtered(array $post)` 只有一个参数）。对 6 组筛选（全区间、≥50%、新人、近 90 天非会员、电话「04」、单日新人；起止日期取自数据库 `MIN/MAX(date_attended)`），比较 `Attendance_DB_Old::query_filtered($post)` 和 `Attendance_DB::query_filtered($post, 'mandarin')` 的 `id, times, percentage, is_new, last_attended, first_name, phone, email, is_member` 以及行顺序。缓存键里加一个随机字段来绕过缓存。
- [ ] **Step 2: 运行** `bin/test.sh` 的同一套 PHP/socket 参数执行 `wp eval-file tests/compare-mandarin.php` → 预期 6 行都是 `same=Y order=Y`
- [ ] **Step 3: 版本号改为 2.6.0，运行 `bin/test.sh`** → 全部 PASS
- [ ] **Step 4: diff stat → 用户确认 → commit** `chore: v2.6.0 multi-congregation`；push 也要先征得用户同意
