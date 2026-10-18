<?php

/*
| Đường /internal/* cho máy gọi máy (docs/KE-HOACH.md, mục 10). Mỗi đường một secret riêng:
| Worker cron chỉ biết secret của tick, CI chỉ biết secret của deploy. Secret trống thì đường đó trả 404.
*/

return [

    // Cloudflare Worker btg-edge gọi /internal/tick mỗi phút.
    'tick_secret' => env('INTERNAL_TICK_SECRET'),

    // CI gọi /internal/deploy sau mỗi lần deploy; script cài đặt gọi /internal/bootstrap một lần.
    'deploy_secret' => env('INTERNAL_DEPLOY_SECRET'),

    // Hàng đợi (tin Telegram) chạy trong nhịp tối đa ngần này giây, sau các việc theo lịch.
    'queue_seconds' => 4,

];
