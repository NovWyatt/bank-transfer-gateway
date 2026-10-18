#!/usr/bin/env bash
# Gọi một đường /internal/* của app, ký HMAC như app/Internal/InternalSignature.php.
#
#   APP_URL=https://... INTERNAL_SECRET=... scripts/internal-call.sh /internal/deploy
#   APP_URL=https://... INTERNAL_SECRET=... scripts/internal-call.sh /internal/bootstrap '{"shop_name":"...","slug":"...","owner_email":"..."}'
#
# Secret chỉ đọc từ biến môi trường, không nhận qua tham số (tham số lộ trong lịch sử shell).
# Thử lại tối đa 6 lần khi không kết nối được hoặc app trả 5xx (Wasmer đang khởi động bản mới);
# mỗi lần ký lại vì chữ ký chỉ có hạn 5 phút. Lỗi 4xx (sai chữ ký, sai dữ liệu) dừng ngay.
set -euo pipefail

path="${1:?Thiếu đường dẫn, ví dụ /internal/deploy}"
body="${2:-}"
: "${APP_URL:?Thiếu APP_URL}"
: "${INTERNAL_SECRET:?Thiếu INTERNAL_SECRET}"

out="$(mktemp)"
payload="$(mktemp)"
trap 'rm -f "$out" "$payload"' EXIT

# Ký và gửi cùng một file: trên Windows, curl đọc tham số dòng lệnh theo bảng mã ANSI nên
# body có dấu tiếng Việt truyền qua --data-binary "..." bị đổi byte, chữ ký không còn khớp.
printf '%s' "$body" > "$payload"

for attempt in 1 2 3 4 5 6; do
    t="$(date +%s)"
    sig="$({ printf '%s.POST %s\n' "$t" "$path"; cat "$payload"; } | openssl dgst -sha256 -hmac "$INTERNAL_SECRET" | awk '{print $NF}')"

    status="$(curl -sS -o "$out" -w '%{http_code}' --max-time 60 -X POST \
        -H "X-Internal-Signature: t=${t},v1=${sig}" \
        -H 'Content-Type: application/json' \
        -H 'Accept: application/json' \
        -H 'User-Agent: btg-internal-call' \
        --data-binary @"$payload" \
        "${APP_URL%/}${path}")" || status=000

    if [ "$status" -ge 200 ] && [ "$status" -lt 300 ]; then
        cat "$out"
        echo
        exit 0
    fi

    if [ "$status" -ge 400 ] && [ "$status" -lt 500 ]; then
        echo "HTTP $status:" >&2
        cat "$out" >&2
        echo >&2
        exit 1
    fi

    echo "Lần $attempt: HTTP $status. $(head -c 300 "$out" 2>/dev/null)" >&2
    [ "$attempt" -lt 6 ] && sleep 15
done

echo "Hết lượt thử." >&2
exit 1
