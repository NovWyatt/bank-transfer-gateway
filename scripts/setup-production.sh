#!/usr/bin/env bash
# Đặt bí mật cho production. Wyatt tự chạy trong Git Bash, ở thư mục gốc repo (docs/KE-HOACH.md, mục 13).
#
# Script sinh bí mật ngay tại máy và đặt thẳng vào nơi cần, không in giá trị ra màn hình:
#   Wasmer (app novwyatt/bank-transfer-gateway): APP_KEY, INTERNAL_TICK_SECRET, INTERNAL_DEPLOY_SECRET,
#       TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_USERNAME, TELEGRAM_WEBHOOK_SECRET
#   Worker Cloudflare btg-edge (tài khoản rirtar.lofi): TICK_SECRET, cùng giá trị với INTERNAL_TICK_SECRET
#   GitHub NovWyatt/bank-transfer-gateway: secret INTERNAL_DEPLOY_SECRET và WASMER_TOKEN, biến WASMER_OWNER
#
# Bản sao lưu: D:\Wyatt\secrets\bank-transfer-gateway.env. Lần chạy sau dùng lại giá trị trong đó, không sinh mới:
# đổi APP_KEY là mất mọi dữ liệu đã mã hóa (secret webhook, khóa của nhà cung cấp).
#
# Dùng:
#   bash scripts/setup-production.sh core        APP_KEY, secret của tick và deploy (Wasmer, Worker, GitHub)
#   bash scripts/setup-production.sh ci          WASMER_TOKEN cho GitHub Actions (tạo ở wasmer.io/settings/access-tokens)
#   bash scripts/setup-production.sh telegram    token bot (tạo qua @BotFather), tên bot, secret webhook
#   bash scripts/setup-production.sh apply       redeploy để secret có hiệu lực, rồi migrate và đăng ký webhook Telegram
#   bash scripts/setup-production.sh bootstrap   tạo shop đầu tiên, in link mời owner
#   bash scripts/setup-production.sh all         chạy lần lượt cả năm bước trên
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP="novwyatt/bank-transfer-gateway"
REPO="NovWyatt/bank-transfer-gateway"
APP_URL="${APP_URL:-https://bank-transfer-gateway.wasmer.app}"
WASMER="${WASMER:-D:/SETUP/wasmer/bin/wasmer.exe}"
BACKUP="${BACKUP:-/d/Wyatt/secrets/bank-transfer-gateway.env}"

command -v openssl >/dev/null || { echo "Cần openssl (có sẵn trong Git Bash)." >&2; exit 1; }

load_backup() {
    if [ -f "$BACKUP" ]; then
        # shellcheck disable=SC1090
        . "$BACKUP"
    fi
}

save_backup() {
    local name="$1" value="$2"
    mkdir -p "$(dirname "$BACKUP")"
    touch "$BACKUP"
    chmod 600 "$BACKUP" 2>/dev/null || true
    grep -v "^${name}=" "$BACKUP" > "$BACKUP.tmp" || true
    printf "%s='%s'\n" "$name" "$value" >> "$BACKUP.tmp"
    mv "$BACKUP.tmp" "$BACKUP"
}

# Đặt secret trên Wasmer: thử tạo, đã có thì cập nhật. Không in giá trị.
wasmer_secret() {
    local name="$1" value="$2"
    if "$WASMER" app secrets create "$name" "$value" --app "$APP" --non-interactive --quiet >/dev/null 2>&1 \
        || "$WASMER" app secrets update "$name" "$value" --app "$APP" --non-interactive --quiet >/dev/null; then
        echo "  Wasmer: đã đặt $name"
    else
        echo "  Wasmer: KHÔNG đặt được $name" >&2
        return 1
    fi
}

confirm() {
    local answer
    read -r -p "$1 [y/N] " answer
    [ "$answer" = "y" ] || [ "$answer" = "Y" ]
}

step_core() {
    echo "== core: APP_KEY, INTERNAL_TICK_SECRET, INTERNAL_DEPLOY_SECRET"
    "$WASMER" whoami

    load_backup
    if [ -z "${APP_KEY:-}" ]; then
        APP_KEY="base64:$(openssl rand -base64 32)"
        save_backup APP_KEY "$APP_KEY"
        echo "  Sinh APP_KEY mới."
    else
        echo "  Dùng lại APP_KEY trong bản sao lưu."
    fi
    if [ -z "${INTERNAL_TICK_SECRET:-}" ]; then
        INTERNAL_TICK_SECRET="$(openssl rand -hex 32)"
        save_backup INTERNAL_TICK_SECRET "$INTERNAL_TICK_SECRET"
    fi
    if [ -z "${INTERNAL_DEPLOY_SECRET:-}" ]; then
        INTERNAL_DEPLOY_SECRET="$(openssl rand -hex 32)"
        save_backup INTERNAL_DEPLOY_SECRET "$INTERNAL_DEPLOY_SECRET"
    fi

    wasmer_secret APP_KEY "$APP_KEY"
    wasmer_secret INTERNAL_TICK_SECRET "$INTERNAL_TICK_SECRET"
    wasmer_secret INTERNAL_DEPLOY_SECRET "$INTERNAL_DEPLOY_SECRET"

    echo "  Cloudflare: kiểm tra tài khoản trước khi đặt TICK_SECRET cho Worker btg-edge."
    (cd "$ROOT/edge" && npx wrangler whoami)
    if confirm "  Tài khoản ở trên là rirtar.lofi@gmail.com?"; then
        (cd "$ROOT/edge" && printf '%s' "$INTERNAL_TICK_SECRET" | npx wrangler secret put TICK_SECRET >/dev/null)
        echo "  Cloudflare: đã đặt TICK_SECRET cho btg-edge"
    else
        echo "  Bỏ qua Worker. Chạy npx wrangler logout rồi npx wrangler login bằng rirtar.lofi, xong chạy lại bước core." >&2
    fi

    printf '%s' "$INTERNAL_DEPLOY_SECRET" | gh secret set INTERNAL_DEPLOY_SECRET --repo "$REPO"
    echo "  GitHub: đã đặt INTERNAL_DEPLOY_SECRET"
}

step_ci() {
    echo "== ci: WASMER_TOKEN cho GitHub Actions"
    echo "  Tạo token ở https://wasmer.io/settings/access-tokens rồi dán vào đây (không hiện chữ)."
    local token
    read -r -s -p "  WASMER_TOKEN: " token
    echo
    [ -n "$token" ] || { echo "  Không có token, bỏ qua." >&2; return 0; }
    printf '%s' "$token" | gh secret set WASMER_TOKEN --repo "$REPO"
    gh variable set WASMER_OWNER --body novwyatt --repo "$REPO"
    echo "  GitHub: đã đặt WASMER_TOKEN và biến WASMER_OWNER. Từ giờ push lên main là tự deploy."
}

step_telegram() {
    echo "== telegram: tạo bot qua @BotFather trước (lệnh /newbot)"
    local token username
    read -r -s -p "  Token bot (không hiện chữ): " token
    echo
    [ -n "$token" ] || { echo "  Không có token, bỏ qua." >&2; return 0; }
    read -r -p "  Tên bot, không có @ (ví dụ btg_notify_bot): " username

    load_backup
    if [ -z "${TELEGRAM_WEBHOOK_SECRET:-}" ]; then
        TELEGRAM_WEBHOOK_SECRET="$(openssl rand -hex 32)"
        save_backup TELEGRAM_WEBHOOK_SECRET "$TELEGRAM_WEBHOOK_SECRET"
    fi
    save_backup TELEGRAM_BOT_TOKEN "$token"
    save_backup TELEGRAM_BOT_USERNAME "${username#@}"

    wasmer_secret TELEGRAM_BOT_TOKEN "$token"
    wasmer_secret TELEGRAM_BOT_USERNAME "${username#@}"
    wasmer_secret TELEGRAM_WEBHOOK_SECRET "$TELEGRAM_WEBHOOK_SECRET"
}

step_apply() {
    echo "== apply: redeploy để secret có hiệu lực, rồi gọi /internal/deploy"
    load_backup
    : "${INTERNAL_DEPLOY_SECRET:?Chưa có INTERNAL_DEPLOY_SECRET, chạy bước core trước}"

    "$WASMER" app secrets update INTERNAL_DEPLOY_SECRET "$INTERNAL_DEPLOY_SECRET" --app "$APP" --non-interactive --quiet --redeploy >/dev/null
    echo "  Đã yêu cầu redeploy. Chờ 30 giây cho bản mới chạy."
    sleep 30

    APP_URL="$APP_URL" INTERNAL_SECRET="$INTERNAL_DEPLOY_SECRET" bash "$ROOT/scripts/internal-call.sh" /internal/deploy
}

step_bootstrap() {
    echo "== bootstrap: tạo shop đầu tiên và link mời owner"
    load_backup
    : "${INTERNAL_DEPLOY_SECRET:?Chưa có INTERNAL_DEPLOY_SECRET, chạy bước core trước}"

    local name slug email body
    read -r -p "  Tên shop: " name
    read -r -p "  Slug trên đường dẫn /app/<slug> (chữ thường, số, gạch nối): " slug
    read -r -p "  Email của owner: " email
    body="$(NAME="$name" SLUG="$slug" EMAIL="$email" node -e 'process.stdout.write(JSON.stringify({shop_name: process.env.NAME, slug: process.env.SLUG, owner_email: process.env.EMAIL}))')"

    APP_URL="$APP_URL" INTERNAL_SECRET="$INTERNAL_DEPLOY_SECRET" bash "$ROOT/scripts/internal-call.sh" /internal/bootstrap "$body"
    echo "  Mở invitation_url ở trên để đặt mật khẩu cho owner. Link dùng một lần."
}

case "${1:-}" in
    core) step_core ;;
    ci) step_ci ;;
    telegram) step_telegram ;;
    apply) step_apply ;;
    bootstrap) step_bootstrap ;;
    all) step_core; step_ci; step_telegram; step_apply; step_bootstrap ;;
    *) sed -n '2,20p' "$0"; exit 1 ;;
esac
