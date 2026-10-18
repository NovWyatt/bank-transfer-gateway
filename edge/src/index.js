/**
 * Worker btg-edge: nhịp mỗi phút cho app trên Wasmer (docs/KE-HOACH.md, mục 10 và 10a).
 *
 * Wasmer không có cron chạy được (spike GĐ0), nên Worker này gọi POST /internal/tick mỗi phút.
 * Request ký HMAC theo đúng app/Internal/InternalSignature.php:
 *   X-Internal-Signature: t={unix},v1={hex(hmac_sha256(secret, "{t}.{METHOD} {path}\n{body}"))}
 *
 * Biến: APP_URL (wrangler.toml). Bí mật: TICK_SECRET, Wyatt tự đặt bằng `npx wrangler secret put TICK_SECRET`.
 */

const TICK_PATH = '/internal/tick';

// Tick của app tự giới hạn dưới 20 giây; chờ thêm cho lần khởi động nguội của Wasmer.
const TIMEOUT_MS = 30_000;

export default {
  async scheduled(controller, env, ctx) {
    ctx.waitUntil(tick(env, controller.scheduledTime));
  },

  // Không phục vụ request nào: workers_dev tắt, không gắn route.
  async fetch() {
    return new Response('Not found', { status: 404 });
  },
};

/**
 * Gọi /internal/tick một lần. Ném lỗi khi app trả mã khác 2xx để lần chạy cron hiện "lỗi" trên dashboard.
 *
 * @param {{APP_URL: string, TICK_SECRET: string}} env
 * @param {number} scheduledTime mili giây, từ ScheduledController
 * @param {typeof fetch} fetcher thay được trong test
 */
export async function tick(env, scheduledTime = Date.now(), fetcher = fetch) {
  if (!env.TICK_SECRET) {
    throw new Error('Chưa đặt TICK_SECRET: chạy `npx wrangler secret put TICK_SECRET`.');
  }

  const url = new URL(TICK_PATH, env.APP_URL);
  const body = '';
  const timestamp = Math.floor(scheduledTime / 1000);

  const response = await fetcher(url, {
    method: 'POST',
    headers: {
      'X-Internal-Signature': await signatureHeader(env.TICK_SECRET, 'POST', url.pathname, body, timestamp),
      Accept: 'application/json',
      'User-Agent': 'btg-edge',
    },
    body,
    signal: AbortSignal.timeout(TIMEOUT_MS),
  });

  const text = await response.text();

  if (!response.ok) {
    throw new Error(`Tick trả ${response.status}: ${text.slice(0, 500)}`);
  }

  // PHP built-in server trả 200 cho lỗi fatal đã in ra trước header: chỉ tin phản hồi JSON có "ran".
  let result;
  try {
    result = JSON.parse(text);
  } catch {
    result = null;
  }

  if (!Array.isArray(result?.ran)) {
    throw new Error(`Tick trả ${response.status} nhưng không phải JSON của app: ${text.slice(0, 500)}`);
  }

  console.log(`Tick ${response.status}: ${text.slice(0, 500)}`);

  return text;
}

/**
 * @returns {Promise<string>} giá trị header X-Internal-Signature
 */
export async function signatureHeader(secret, method, path, body, timestamp) {
  const encoder = new TextEncoder();
  const key = await crypto.subtle.importKey('raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const message = `${timestamp}.${method.toUpperCase()} ${path}\n${body}`;
  const mac = new Uint8Array(await crypto.subtle.sign('HMAC', key, encoder.encode(message)));
  const hex = Array.from(mac, (byte) => byte.toString(16).padStart(2, '0')).join('');

  return `t=${timestamp},v1=${hex}`;
}
