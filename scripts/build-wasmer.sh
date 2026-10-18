#!/usr/bin/env bash
# Đóng gói thư mục build/wasmer sạch để deploy lên Wasmer Edge.
# - Chỉ lấy file đã commit (git archive), nên không có .env hay file rác của máy.
# - Chỉ cài thư viện production.
# - Bí mật không nằm trong gói: đặt bằng `wasmer app secrets create`.
#
# Biến môi trường:
#   PHP_BIN        đường dẫn php (mặc định: php)
#   COMPOSER_PHAR  đường dẫn composer.phar (mặc định: gọi lệnh composer)
#   SPIKE_TOKEN    token của route spike, điền vào job fetch trong app.yaml
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/build/wasmer"
PHP_BIN="${PHP_BIN:-php}"

composer_run() {
    if [ -n "${COMPOSER_PHAR:-}" ]; then
        "$PHP_BIN" -d xdebug.mode=off "$COMPOSER_PHAR" "$@"
    else
        composer "$@"
    fi
}

if [ -n "$(git -C "$ROOT" status --porcelain)" ]; then
    echo "Còn thay đổi chưa commit; gói chỉ lấy HEAD nên chúng sẽ bị bỏ qua." >&2
fi

rm -rf "$OUT"
mkdir -p "$OUT"
git -C "$ROOT" archive HEAD | tar -x -C "$OUT"

cp "$ROOT/deploy/wasmer/wasmer.toml" "$OUT/wasmer.toml"
sed "s|__SPIKE_TOKEN__|${SPIKE_TOKEN:-}|g" "$ROOT/deploy/wasmer/app.yaml" > "$OUT/app.yaml"

cd "$OUT"
composer_run install --no-dev --optimize-autoloader --no-interaction --no-progress

# Không cần trên server.
rm -rf tests docs deploy scripts .github .githooks phpunit.xml phpstan.neon AGENTS.md CLAUDE.md

echo "Đã đóng gói vào $OUT ($(du -sh "$OUT" | cut -f1))."
