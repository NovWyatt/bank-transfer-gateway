<?php

namespace App\Payments\Providers\Exceptions;

use RuntimeException;

/** Chữ ký đúng nhưng nội dung không đọc được. Đường nhận trả 400 và lưu lại để tra. */
final class InvalidPayload extends RuntimeException {}
