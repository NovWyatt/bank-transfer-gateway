/*
 * Trang thanh toán: đếm ngược, nút chép, hỏi trạng thái 5 giây một lần.
 * Khi trạng thái đổi, tải lại phần <main> do server dựng (Blade là nơi duy nhất viết chữ),
 * thay vào trang để nền đổi màu và câu chạy lại hiệu ứng, không tải lại cả trang.
 */
(() => {
    'use strict';

    const POLL_MS = 5000;
    const POLL_LIMIT_MS = 15 * 60 * 1000;
    const startedAt = Date.now();

    let main = document.querySelector('[data-pay]');
    if (!main) return;

    let countdownTimer = null;
    let pollTimer = null;

    function announce(text) {
        const live = main.querySelector('[data-live]');
        if (live) live.textContent = text;
    }

    function bindCopy(root) {
        root.querySelectorAll('[data-copy]').forEach((button) => {
            button.addEventListener('click', async () => {
                const value = button.dataset.copy;
                try {
                    await navigator.clipboard.writeText(value);
                } catch {
                    return; // Không chép được (trình duyệt cũ, không phải https): giá trị vẫn hiện để chép tay.
                }
                button.dataset.copied = '';
                if (button.classList.contains('copy')) button.textContent = 'Đã chép';
                announce('Đã chép ' + value + '.');
                setTimeout(() => {
                    delete button.dataset.copied;
                    if (button.classList.contains('copy')) button.textContent = 'Chép';
                }, 1800);
            });
        });
    }

    function startCountdown(root) {
        clearInterval(countdownTimer);
        const label = root.querySelector('[data-countdown]');
        const expires = Date.parse(root.dataset.expires);
        if (!label || Number.isNaN(expires)) return;

        const tick = () => {
            const left = Math.max(0, Math.round((expires - Date.now()) / 1000));
            const minutes = Math.floor(left / 60);
            const seconds = String(left % 60).padStart(2, '0');
            label.textContent = left > 0 ? `Còn ${minutes}:${seconds}` : 'Hết hạn';
            if (left === 0) {
                clearInterval(countdownTimer);
                check(); // Hết giờ: hỏi ngay để trang chuyển sang "hết hạn".
            }
        };
        tick();
        countdownTimer = setInterval(tick, 1000);
    }

    async function swapPage() {
        const response = await fetch(window.location.href, { headers: { Accept: 'text/html' }, cache: 'no-store' });
        if (!response.ok) return;
        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        const fresh = doc.querySelector('[data-pay]');
        if (!fresh) return;

        main.replaceWith(fresh);
        main = fresh;
        document.body.dataset.state = fresh.dataset.state;
        document.title = doc.title;
        init(fresh);
    }

    async function check() {
        try {
            const response = await fetch(main.dataset.statusUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (response.ok) {
                const status = await response.json();
                if (status.signature !== main.dataset.signature) {
                    await swapPage();
                    return;
                }
            }
        } catch {
            // Mất mạng một nhịp: lần sau hỏi lại.
        }
        schedule();
    }

    function schedule() {
        clearTimeout(pollTimer);
        if (main.dataset.open !== '1') return;
        if (Date.now() - startedAt > POLL_LIMIT_MS) {
            announce('Trang đã ngừng tự cập nhật. Tải lại trang để xem trạng thái mới nhất.');
            return;
        }
        pollTimer = setTimeout(check, POLL_MS);
    }

    function init(root) {
        bindCopy(root);
        startCountdown(root);
        schedule();
    }

    init(main);
})();
