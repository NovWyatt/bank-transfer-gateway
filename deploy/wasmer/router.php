<?php

/*
| Router cho PHP built-in server trên Wasmer (wasmer.toml, lệnh run).
|
| Không có router, built-in server tự trả 404 cho mọi đường có đuôi file mà không có file thật trong
| public/, trước khi tới Laravel: /livewire-…/livewire.min.js (mọi form của dashboard chết),
| /qr/{code}.svg (trang thanh toán mất mã QR), /docs/api.json. Router này trả thẳng file có thật,
| còn lại đưa vào public/index.php, giống `php artisan serve`.
*/

$public = (string) $_SERVER['DOCUMENT_ROOT'];
$uri = urldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));

// '..' không bao giờ trỏ ra ngoài public/: để Laravel xử lý (404) thay vì hỏi hệ thống file.
if ($uri !== '/' && ! str_contains($uri, '..') && is_file($public.$uri)) {
    return false;
}

require $public.'/index.php';
