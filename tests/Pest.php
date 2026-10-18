<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Test Feature chạy trên MySQL/MariaDB thật (xem phpunit.xml), vì khớp đơn
| dựa vào lockForUpdate và ràng buộc UNIQUE mà SQLite không mô phỏng đúng.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
