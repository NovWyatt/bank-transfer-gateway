<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('description')->nullable();
            // whsec_…, cast encrypted: chỉ hiện một lần lúc tạo hoặc lúc đổi.
            $table->text('secret');
            // Danh sách loại sự kiện đăng ký nhận, ví dụ ["order.paid", "order.expired"].
            $table->json('events');
            $table->boolean('active')->default(true);
            // Chuỗi hỏng hiện tại: đủ cả số lần lẫn thời gian thì tự tắt (config/webhooks.php).
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('failing_since')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
