<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hàng chờ gửi (outbox): tạo trong cùng DB transaction với việc đổi trạng thái đơn.
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            // evt_ + ULID, gửi trong header X-Gateway-Event-Id để phía shop bỏ trùng.
            $table->string('event_id', 40);
            $table->string('event_type', 40);
            // JSON đã mã hóa một lần; chữ ký tính trên đúng các byte này ở mọi lần gửi.
            $table->mediumText('payload');
            $table->string('status', 16);
            // Số lần gửi tự động đã chạy (lần đầu + các lần gửi lại). Gửi tay không tính.
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_response_code')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['webhook_endpoint_id', 'event_id']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['shop_id', 'created_at']);
        });

        // Nhật ký từng lần gửi: mã HTTP, 2 KB đầu của phản hồi, thời gian.
        Schema::create('webhook_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_delivery_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 16);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            // Lỗi kết nối, quá giờ, hoặc URL bị chặn: khi đó không có response_code.
            $table->string('error')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_attempts');
        Schema::dropIfExists('webhook_deliveries');
    }
};
