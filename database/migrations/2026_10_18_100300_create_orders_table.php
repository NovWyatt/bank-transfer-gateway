<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            // Giữ tài khoản nhận tiền của đơn: QR đã phát ra phải còn hiển thị đúng.
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            // Duy nhất toàn hệ thống: mã trong nội dung chuyển khoản không mang theo shop.
            $table->string('code', 10)->unique();
            $table->string('external_ref', 100)->nullable();
            // Số tiền VND là số nguyên, không dùng float.
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->string('status', 20);
            $table->string('description')->nullable();
            $table->string('customer_name')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->boolean('paid_after_expiry')->default(false);
            $table->string('created_via', 16);
            $table->json('metadata')->nullable();
            $table->timestamps();

            // MySQL cho nhiều NULL trong UNIQUE, nên các đơn không có external_ref không đụng nhau.
            $table->unique(['shop_id', 'external_ref']);
            $table->index(['shop_id', 'status', 'created_at']);
            // Lệnh đánh dấu hết hạn quét theo cặp này.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
