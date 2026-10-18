<?php

/**
 * Ví dụ nơi nhận webhook, viết bằng PHP thuần.
 *
 * Chạy thử ở máy:
 *   GATEWAY_WEBHOOK_SECRET=whsec_... php -S 127.0.0.1:9000 receiver.php
 *
 * Ba điều quan trọng:
 *   1. Kiểm chữ ký trên body gốc trước khi tin bất cứ thứ gì trong đó.
 *   2. Một sự kiện có thể đến nhiều lần (gateway gửi lại khi bạn trả lỗi hoặc quá 10 giây): bỏ trùng theo id.
 *   3. Trả 2xx nhanh. Việc nặng (gửi email, gọi kho) thì đẩy vào hàng đợi của bạn.
 */

require __DIR__.'/verify-signature.php';

function respond(int $status, string $body): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $body;
    exit;
}

$secret = (string) getenv('GATEWAY_WEBHOOK_SECRET');
$payload = (string) file_get_contents('php://input');
$header = $_SERVER['HTTP_X_GATEWAY_SIGNATURE'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, 'POST only');
}

if ($secret === '' || ! gateway_verify_signature($payload, $header, $secret)) {
    respond(400, 'invalid signature');
}

$event = json_decode($payload, true);

// Ứng dụng thật: bảng processed_events với UNIQUE(event_id). Ở đây dùng file tạm cho gọn.
$seenFile = sys_get_temp_dir().'/gateway-webhook-seen.txt';
$seen = is_file($seenFile) ? file($seenFile, FILE_IGNORE_NEW_LINES) : [];

if (in_array($event['id'], $seen, true)) {
    respond(200, 'duplicate');
}

$order = $event['data']['order'] ?? null;

switch ($event['type']) {
    case 'order.paid':
        // Đơn đã nhận đủ tiền: tìm đơn theo $order['external_ref'] (mã đơn bên bạn) hoặc $order['code'], rồi giao hàng.
        break;
    case 'order.partially_paid':
    case 'order.overpaid':
        // Thiếu hoặc dư tiền: báo người bán xử lý tay.
        break;
    case 'order.expired':
    case 'order.cancelled':
        // Nhả hàng đã giữ cho đơn.
        break;
    case 'transaction.unmatched':
        // Tiền vào nhưng không khớp đơn nào (khách ghi sai nội dung): xem trên dashboard của gateway.
        break;
    case 'webhook.ping':
        break;
}

file_put_contents($seenFile, $event['id'].PHP_EOL, FILE_APPEND | LOCK_EX);
error_log(sprintf('[gateway] %s %s %s', $event['id'], $event['type'], $order['code'] ?? '-'));

respond(200, 'ok');
