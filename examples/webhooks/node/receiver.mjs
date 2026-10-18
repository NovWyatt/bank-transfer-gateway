/**
 * Ví dụ nơi nhận webhook, viết bằng Node thuần (không Express).
 *
 * Chạy thử ở máy:
 *   GATEWAY_WEBHOOK_SECRET=whsec_... PORT=9000 node receiver.mjs
 *
 * Ba điều quan trọng:
 *   1. Kiểm chữ ký trên body gốc trước khi tin bất cứ thứ gì trong đó.
 *   2. Một sự kiện có thể đến nhiều lần (gateway gửi lại khi bạn trả lỗi hoặc quá 10 giây): bỏ trùng theo id.
 *   3. Trả 2xx nhanh. Việc nặng (gửi email, gọi kho) thì đẩy vào hàng đợi của bạn.
 */
import { createServer } from 'node:http';
import { verifyGatewaySignature } from './verify-signature.mjs';

const secret = process.env.GATEWAY_WEBHOOK_SECRET ?? '';
const port = Number(process.env.PORT ?? 9000);

// Ứng dụng thật: bảng processed_events với UNIQUE(event_id). Ở đây giữ trong bộ nhớ cho gọn.
const seen = new Set();

const server = createServer((request, response) => {
  const reply = (status, body) => response.writeHead(status, { 'Content-Type': 'text/plain; charset=utf-8' }).end(body);

  if (request.method !== 'POST') return reply(405, 'POST only');

  const chunks = [];
  request.on('data', (chunk) => chunks.push(chunk));
  request.on('end', () => {
    const payload = Buffer.concat(chunks);

    if (secret === '' || !verifyGatewaySignature(payload, request.headers['x-gateway-signature'], secret)) {
      return reply(400, 'invalid signature');
    }

    const event = JSON.parse(payload.toString('utf8'));

    if (seen.has(event.id)) return reply(200, 'duplicate');

    const order = event.data?.order ?? null;

    switch (event.type) {
      case 'order.paid':
        // Đơn đã nhận đủ tiền: tìm đơn theo order.external_ref (mã đơn bên bạn) hoặc order.code, rồi giao hàng.
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
    }

    seen.add(event.id);
    console.log(`[gateway] ${event.id} ${event.type} ${order?.code ?? '-'}`);

    return reply(200, 'ok');
  });
});

server.listen(port, '127.0.0.1', () => console.log(`Đang nghe http://127.0.0.1:${port}`));
