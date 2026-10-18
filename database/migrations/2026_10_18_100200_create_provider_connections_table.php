<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_connections', function (Blueprint $table) {
            $table->id();
            // Nằm trong URL webhook /hooks/{provider}/{uuid}. Dùng UUIDv4 cho đủ ngẫu nhiên.
            $table->uuid('uuid')->unique();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            // Null: lấy tài khoản theo số tài khoản trong payload (một tài khoản SePay có thể nối nhiều ngân hàng).
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label')->nullable();
            // Cast encrypted:array, nên cột chứa chuỗi đã mã hóa.
            $table->text('credentials');
            $table->string('status', 16)->default('active');
            $table->timestamp('last_received_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_connections');
    }
};
