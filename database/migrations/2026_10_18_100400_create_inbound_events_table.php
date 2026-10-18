<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Request gốc từ nhà cung cấp, để tra lỗi và chạy lại. Giữ 30 ngày.
        Schema::create('inbound_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_connection_id')->constrained()->cascadeOnDelete();
            // Đã che Authorization và các header chứa khóa trước khi lưu.
            $table->json('headers');
            $table->mediumText('body');
            $table->boolean('signature_valid');
            $table->string('ip', 45)->nullable();
            $table->string('error')->nullable();
            $table->timestamp('received_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_events');
    }
};
