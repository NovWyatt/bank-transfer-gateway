<?php

/*
| Webhook gửi tới hệ thống của shop (mục 5.5 của kế hoạch).
| Việc gửi chạy trong nhịp mỗi phút (Schedule::call), không có worker chạy liên tục.
*/

return [

    // Lần đầu gửi ở nhịp kế tiếp (chậm nhất 1 phút sau sự kiện). Hỏng thì gửi lại sau các mốc này
    // (phút, tính từ lần hỏng trước): 7 lần gửi lại trong khoảng 22 giờ, hết thì chuyển "failed".
    'retry_minutes' => [1, 5, 15, 60, 180, 360, 720],

    // Thành công là nhận 2xx trong ngần này giây. 3xx tính là hỏng: không đi theo chuyển hướng.
    'timeout' => 10,
    'connect_timeout' => 5,

    // Mỗi nhịp chỉ bắt đầu lần gửi mới trong ngần này giây. Cộng timeout là dưới 20 giây,
    // vì Wasmer chạy single_concurrency và nhịp sau không được chờ nhịp trước.
    'tick_budget_seconds' => 8,
    'batch_size' => 20,

    // Bản ghi đã nhận về để gửi thì dời lịch đi ngần này giây. Tiến trình chết giữa chừng
    // thì hết hạn giữ chỗ, nhịp sau gửi lại.
    'lease_seconds' => 120,

    // Nhật ký giữ 2 KB đầu của phản hồi. Phản hồi quá 1 MB thì curl cắt và tính là hỏng.
    'response_snippet_bytes' => 2048,
    'max_response_bytes' => 1_048_576,

    // Endpoint tự tắt khi hỏng liên tiếp ít nhất ngần này lần VÀ đã hỏng liên tục ngần này giờ:
    // một đợt sập ngắn của shop không làm tắt, endpoint chết hẳn thì không bị gọi mãi.
    'disable_after_failures' => 20,
    'disable_after_hours' => 24,

    // Bản ghi gửi đã xong (thành công, thất bại, bỏ qua) giữ ngần này ngày rồi xóa.
    'retention_days' => 30,

    // Chỉ cho máy dev: cho phép http:// và địa chỉ nội bộ để thử với script mẫu chạy ở localhost.
    // Production luôn tắt dù biến môi trường bật (xem AppServiceProvider).
    'allow_private_targets' => (bool) env('WEBHOOKS_ALLOW_PRIVATE_TARGETS', false),

];
