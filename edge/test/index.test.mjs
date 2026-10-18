// Chạy: npm test (trong edge/) hoặc node --test "edge/test/*.test.mjs"
// PHP_VECTOR cũng nằm trong tests/Unit/Internal/InternalSignatureTest.php: Worker và app ký lệch nhau là một trong hai test hỏng.

import assert from 'node:assert/strict';
import { test } from 'node:test';

import { signatureHeader, tick } from '../src/index.js';

const PHP_VECTOR = 't=1760000000,v1=067e7cebc52a503cf48e6987bd10182294831d264abe48a07419cc57066466f8';

test('ký giống hệt PHP', async () => {
  assert.equal(await signatureHeader('bi-mat-edge', 'POST', '/internal/tick', '', 1760000000), PHP_VECTOR);
  assert.equal(await signatureHeader('bi-mat-edge', 'post', '/internal/tick', '', 1760000000), PHP_VECTOR);
  assert.notEqual(await signatureHeader('bi-mat-edge', 'POST', '/internal/deploy', '', 1760000000), PHP_VECTOR);
});

test('tick gọi đúng URL, ký theo giờ của lần cron, không để secret trong URL', async () => {
  const calls = [];
  const fakeFetch = async (url, init) => {
    calls.push({ url: String(url), init });
    return new Response('{"ran":["orders-expire"],"failed":[]}', { status: 200 });
  };

  await tick({ APP_URL: 'https://pay.example.test/', TICK_SECRET: 'bi-mat-edge' }, 1760000000_999, fakeFetch);

  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'https://pay.example.test/internal/tick');
  assert.equal(calls[0].init.method, 'POST');
  assert.equal(calls[0].init.headers['X-Internal-Signature'], PHP_VECTOR);
  assert.ok(!calls[0].url.includes('bi-mat-edge'));
});

test('tick ném lỗi khi app trả mã khác 2xx', async () => {
  const fakeFetch = async () => new Response('{"code":"invalid_signature"}', { status: 401 });

  await assert.rejects(
    tick({ APP_URL: 'https://pay.example.test', TICK_SECRET: 'sai' }, Date.now(), fakeFetch),
    /Tick trả 401/,
  );
});

test('tick ném lỗi khi 200 mà không phải JSON của app (lỗi fatal của PHP)', async () => {
  const fakeFetch = async () => new Response('<b>Fatal error</b>: Uncaught Error', { status: 200 });

  await assert.rejects(
    tick({ APP_URL: 'https://pay.example.test', TICK_SECRET: 'bi-mat-edge' }, Date.now(), fakeFetch),
    /không phải JSON của app/,
  );
});

test('tick dừng ngay khi chưa đặt TICK_SECRET', async () => {
  await assert.rejects(tick({ APP_URL: 'https://pay.example.test' }, Date.now(), async () => assert.fail('không được gọi mạng')), /TICK_SECRET/);
});
