<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spike_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('note')->nullable();
            $table->timestamp('created_at', 3)->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spike_heartbeats');
    }
};
