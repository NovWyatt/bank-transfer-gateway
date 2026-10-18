import { createHmac, timingSafeEqual } from 'node:crypto';

/**
 * Kiểm chữ ký webhook của Bank Transfer Gateway. Node 18 trở lên, không cần thư viện ngoài.
 *
 * Header: X-Gateway-Signature: t=1760752800,v1=5257a869…
 * Chữ ký: hex(hmac_sha256(secret, "{t}.{body gốc}")), khóa là nguyên chuỗi secret (kể cả "whsec_").
 *
 * @param {Buffer|string} payload body gốc. Với Express dùng express.raw({ type: 'application/json' }),
 *                                đừng JSON.parse rồi JSON.stringify lại: lệch một dấu cách là chữ ký sai.
 * @param {string|undefined} header giá trị header X-Gateway-Signature
 * @param {string} secret secret của endpoint, hiện một lần trên dashboard lúc tạo
 * @param {{ toleranceSeconds?: number, now?: number }} [options] lệch giờ tối đa (giây) và giờ hiện tại (unix)
 * @returns {boolean}
 */
export function verifyGatewaySignature(payload, header, secret, { toleranceSeconds = 300, now = Math.floor(Date.now() / 1000) } = {}) {
  let timestamp = null;
  const signatures = [];

  for (const part of String(header ?? '').split(',')) {
    const separator = part.indexOf('=');
    if (separator === -1) continue;

    const key = part.slice(0, separator).trim();
    const value = part.slice(separator + 1).trim();

    if (key === 't' && /^\d+$/.test(value)) {
      timestamp = Number(value);
    } else if (key === 'v1') {
      // Có thể có nhiều v1 (ví dụ lúc đổi secret): đúng một cái là đủ.
      signatures.push(value);
    }
  }

  if (timestamp === null || signatures.length === 0) return false;
  if (Math.abs(now - timestamp) > toleranceSeconds) return false;

  const expected = createHmac('sha256', secret).update(`${timestamp}.`).update(payload).digest();

  return signatures.some((signature) => {
    const given = Buffer.from(signature, 'hex');

    // timingSafeEqual so sánh hằng thời gian, nhưng đòi hai bên cùng độ dài.
    return given.length === expected.length && timingSafeEqual(given, expected);
  });
}
