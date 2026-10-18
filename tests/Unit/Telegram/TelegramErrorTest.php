<?php

use App\Telegram\TelegramError;

/*
| Lỗi nào của Bot API nghĩa là chat không còn nhận tin (ngắt shop), lỗi nào chỉ là tạm thời hoặc lỗi của app.
| Thông điệp mẫu lấy theo đúng dạng TelegramClient tạo ra: "Telegram từ chối <method>: <description>".
*/

it('phân loại lỗi theo mã và mô tả của Telegram', function (int $status, string $description, ?string $migrateTo, bool $unreachable): void {
    $error = new TelegramError("Telegram từ chối sendMessage: {$description}", $status, migrateToChatId: $migrateTo);

    expect($error->chatUnreachable())->toBe($unreachable);
})->with([
    'bị chặn' => [403, 'Forbidden: bot was blocked by the user', null, true],
    'bị đuổi khỏi nhóm' => [403, 'Forbidden: bot was kicked from the group chat', null, true],
    'tài khoản đã xóa' => [403, 'Forbidden: user is deactivated', null, true],
    'chat đã mất' => [400, 'Bad Request: chat not found', null, true],
    'nhóm thành supergroup' => [400, 'Bad Request: group chat was upgraded to a supergroup chat', '-1001234567890', false],
    'tin quá dài' => [400, 'Bad Request: message is too long', null, false],
    'token bot sai' => [401, 'Unauthorized', null, false],
    'bị giới hạn tần suất' => [429, 'Too Many Requests: retry after 5', null, false],
    'Telegram lỗi' => [502, 'Bad Gateway', null, false],
    'mất kết nối' => [0, 'cURL error 28: timed out', null, false],
]);
