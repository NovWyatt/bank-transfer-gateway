<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->char('bank_bin', 6);
            $table->string('bank_code', 16);
            $table->string('account_number', 19);
            $table->string('account_name');
            $table->string('label')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['shop_id', 'bank_bin', 'account_number']);
            // Đường nhận tìm tài khoản theo số tài khoản trong payload.
            $table->index('account_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
