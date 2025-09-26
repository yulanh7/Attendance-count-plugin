#!/usr/bin/env bash
set -euo pipefail

# ============ 配置默认值 ============
SCRIPT_PATH="$(cd "$(dirname "$0")" && pwd)"
DEFAULT_ROOT="$SCRIPT_PATH"          # 默认以脚本所在目录作为项目根
DEFAULT_OUT="$SCRIPT_PATH/_export"   # 默认导出目录
DEFAULT_ZIP_NAME="project_upload.zip"
UPLOAD_FLAT_DIR_NAME="_upload_flat"  # 扁平化目录名（位于输出目录中）
IGNORE_DIRS_REGEX="node_modules|vendor|.git|.idea|.vscode|dist|build|coverage|.cache|__pycache__"

# ============ 参数解析 ============
ROOT="$DEFAULT_ROOT"
OUT_DIR="$DEFAULT_OUT"
ZIP_NAME="$DEFAULT_ZIP_NAME"

usage() {
  cat <<USAGE
Usage: $(basename "$0") [--root <project_root>] [--out <output_dir>] [--name <zip_name>]

Options:
  --root   项目根目录（默认：脚本所在目录）
  --out    输出目录：zip、tree.txt、mapping.json、扁平化副本都放这里（默认：<root>/_export）
  --name   zip 文件名（默认：project_upload.zip）

Examples:
  $(basename "$0")
  $(basename "$0") --out ./_export
  $(basename "$0") --root /path/to/plugin --out /tmp/export --name attendance_upload.zip
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --root)
      ROOT="$(cd "$2" && pwd)"; shift 2;;
    --out)
      OUT_DIR="$(mkdir -p "$2" && cd "$2" && pwd)"; shift 2;;
    --name)
      ZIP_NAME="$2"; shift 2;;
    -h|--help)
      usage; exit 0;;
    *)
      echo "Unknown option: $1"; usage; exit 1;;
  esac
done

# ============ 路径与变量 ============
mkdir -p "$OUT_DIR"
FLAT_DIR="$OUT_DIR/$UPLOAD_FLAT_DIR_NAME"
TREE_TXT="$OUT_DIR/tree.txt"
MAPPING_JSON="$OUT_DIR/mapping.json"
ZIP_PATH="$OUT_DIR/$ZIP_NAME"

echo "Root     : $ROOT"
echo "Output   : $OUT_DIR"
echo "Flat dir : $FLAT_DIR"
echo "Zip file : $ZIP_PATH"
echo

# ============ 1) 生成 tree.txt ============
echo "-> 生成 tree.txt ..."
if command -v tree >/dev/null 2>&1; then
  (cd "$ROOT" && tree -a -I "$IGNORE_DIRS_REGEX") > "$TREE_TXT"
else
  echo "(未检测到 tree，使用 find 生成简版目录结构)"
  # 仅在 ROOT 内查找，忽略常见大目录；屏蔽权限错误输出
  (cd "$ROOT" && \
    find . \
      -path "./.git" -prune -o \
      -path "./node_modules" -prune -o \
      -path "./vendor" -prune -o \
      -path "./dist" -prune -o \
      -path "./build" -prune -o \
      -path "./.idea" -prune -o \
      -path "./.vscode" -prune -o \
      -path "./coverage" -prune -o \
      -path "./.cache" -prune -o \
      -print \
  ) 2>/dev/null > "$TREE_TXT"
fi
echo "   写入：$TREE_TXT"

# ============ 2) 生成 mapping.json ============
echo "-> 生成 mapping.json ..."
# 传递 ROOT/OUT_DIR 给内嵌 Python
CHATGPT_ROOT="$ROOT" CHATGPT_OUT="$OUT_DIR" python3 - <<'PY'
import os, re, json, hashlib, mimetypes, sys

ROOT = os.environ.get("CHATGPT_ROOT")
OUT = os.environ.get("CHATGPT_OUT")
IGNORE_PAT = re.compile(r'(?:^|/)(?:node_modules|vendor|\.git|dist|build|\.idea|\.vscode|coverage|\.cache|__pycache__)(?:/|$)')

def sha1sum(path):
    h = hashlib.sha1()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(65536), b''):
            h.update(chunk)
    return h.hexdigest()

def guess_language(path):
    ext = os.path.splitext(path)[1].lower()
    return {
        '.php': 'php',
        '.js': 'javascript',
        '.ts': 'typescript',
        '.tsx': 'typescriptreact',
        '.jsx': 'javascriptreact',
        '.css': 'css',
        '.scss': 'scss',
        '.json': 'json',
        '.po': 'po',
        '.mo': 'mo',
        '.pot': 'pot',
        '.sql': 'sql',
        '.md': 'markdown',
        '.yml': 'yaml',
        '.yaml': 'yaml',
        '.xml': 'xml',
        '.sh': 'bash',
    }.get(ext, (mimetypes.guess_type(path)[0] or 'text/plain'))

def guess_purpose(path):
    p = path.lower()
    if p.endswith('4c-attendance.php') or p == '4c-attendance.php':
        return 'plugin bootstrap / main entry'
    if p.startswith('admin/'):
        return 'wp-admin pages & settings'
    if p.startswith('includes/'):
        return 'business logic / services / hooks'
    if p.startswith('public/'):
        return 'shortcodes / blocks / public assets'
    if p.startswith('assets/'):
        return 'static assets (js/css/img)'
    if p.startswith('languages/'):
        return 'i18n files'
    return 'misc'

mapping = {}
for r, dirs, files in os.walk(ROOT):
    # 过滤忽略目录
    rel_dir = os.path.relpath(r, ROOT)
    if rel_dir == '.':
        rel_dir = ''
    # 就地修改 dirs 以便 os.walk 不进入忽略目录
    dirs[:] = [d for d in dirs if not IGNORE_PAT.search(os.path.join(rel_dir, d))]
    for fn in files:
        rel = os.path.normpath(os.path.join(rel_dir, fn)).replace('\\','/')
        if rel == '.' or rel == '':
            continue
        if IGNORE_PAT.search(rel):
            continue
        if rel in ('mapping.json', 'tree.txt'):
            # 避免把导出的 mapping/tree（若放在ROOT）也收进来
            continue
        abs_path = os.path.join(ROOT, rel)
        if not os.path.isfile(abs_path):
            continue
        try:
            st = os.stat(abs_path)
        except (FileNotFoundError, PermissionError):
            continue
        entry = rel in ("4c-attendance.php",)
        mapping[rel] = {
            "path": rel,
            "size": st.st_size,
            "sha1": sha1sum(abs_path),
            "language": guess_language(rel),
            "purpose": guess_purpose(rel),
            "entry": entry
        }

out_path = os.path.join(OUT, "mapping.json")
with open(out_path, 'w', encoding='utf-8') as f:
    json.dump({
        "project": "4C.Attendance Plugin",
        "php_version_target": "8.0.x",
        "wordpress_target": "6.x",
        "files": mapping
    }, f, ensure_ascii=False, indent=2)

print(f"   写入：{out_path}（{len(mapping)} 个文件）")
PY

# ============ 3) 生成扁平化副本 ============
echo "-> 生成扁平化副本（文件名包含路径） ..."
rm -rf "$FLAT_DIR"
mkdir -p "$FLAT_DIR"

# 遍历 ROOT 内的文件复制；用 __ 替换路径分隔符
while IFS= read -r -d '' file; do
  rel="${file#"$ROOT/"}"
  # 跳过忽略目录/文件
  if [[ "$rel" =~ ^($UPLOAD_FLAT_DIR_NAME/|\.git/|node_modules/|vendor/|dist/|build/|\.idea/|\.vscode/|coverage/|\.cache|__pycache__/|mapping\.json|tree\.txt)$ ]]; then
    continue
  fi
  flat_name="${rel//\//__}"
  dest="$FLAT_DIR/$flat_name"
  mkdir -p "$(dirname "$dest")" 2>/dev/null || true
  cp "$file" "$dest"
done < <(find "$ROOT" -type f -print0 2>/dev/null)

# 附带 mapping.json 与 tree.txt
cp "$MAPPING_JSON" "$FLAT_DIR/mapping.json"
cp "$TREE_TXT" "$FLAT_DIR/tree.txt"
echo "   扁平化目录：$FLAT_DIR"

# ============ 4) 打包 zip ============
echo "-> 打包 zip ..."
rm -f "$ZIP_PATH"
# 在 ROOT 内打包当前项目，但不包含忽略目录和扁平化副本
(
  cd "$ROOT"
  zip -r "$ZIP_PATH" . \
    -x "*/.git/*" "*/node_modules/*" "*/vendor/*" "*/dist/*" "*/build/*" "*/.idea/*" "*/.vscode/*" "*/coverage/*" "*/.cache/*" "*/__pycache__/*" \
    -x "$UPLOAD_FLAT_DIR_NAME/*" \
    >/dev/null
)
# 再把导出的 mapping 与 tree 放进 zip（位于 zip 根目录）
(
  cd "$OUT_DIR"
  zip -j "$ZIP_PATH" "mapping.json" "tree.txt" >/dev/null
)

echo "✅ 完成：
- 代码树：$TREE_TXT
- 结构映射：$MAPPING_JSON
- 扁平化副本：$FLAT_DIR
- 打包文件：$ZIP_PATH
"
