<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inbound_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 16);
            $table->string('external_id', 64);
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 3);
            $table->unsignedBigInteger('amount');
            $table->string('content', 500)->nullable();
            $table->string('reference_code', 100)->nullable();
            $table->string('provider_code', 64)->nullable();
            $table->timestamp('transacted_at');
            $table->string('payer_name')->nullable();
            $table->string('payer_account', 32)->nullable();
            $table->json('raw');
            // sha256(tài khoản | mã tham chiếu | số tiền | phút): bắt cùng giao dịch đến qua hai nhà cung cấp.
            $table->char('fingerprint', 64)->nullable()->index();
            $table->string('status', 16);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('matched_by', 24)->nullable();
            $table->foreignId('matched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('duplicate_of')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();

            // Chống ghi hai lần khi nhà cung cấp gửi lại. Khóa có shop_id để một shop không thể
            // giả webhook mang external_id của shop khác rồi chặn mất giao dịch thật của họ.
            $table->unique(['shop_id', 'provider', 'external_id']);
            $table->index(['shop_id', 'status', 'transacted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
