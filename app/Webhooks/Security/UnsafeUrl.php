<?php

namespace App\Webhooks\Security;

use RuntimeException;

/** URL webhook không được phép gửi tới. Thông điệp hiện thẳng cho owner, viết tiếng Việt. */
final class UnsafeUrl extends RuntimeException {}
