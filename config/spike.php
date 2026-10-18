<?php

/*
| Spike hạ tầng Wasmer ở giai đoạn 0 (docs/KE-HOACH.md, mục 10).
| Chỉ bật khi SPIKE_ENABLED=true. Xóa cả nhánh này sau khi ghi kết quả.
*/
return [
    'enabled' => (bool) env('SPIKE_ENABLED', false),
    'token' => env('SPIKE_TOKEN'),
];
