<?php

namespace App\Payments\Providers\Exceptions;

use RuntimeException;

/** Request không chứng minh được là đến từ nhà cung cấp. Đường nhận trả 401. */
final class InvalidSignature extends RuntimeException {}
