<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recognized_login_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['user_id', 'token_hash']);
        });

        Schema::create('login_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recognized_login_device_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('device');
            $table->string('ip_address', 45)->nullable();
            $table->string('location', 100)->nullable();
            $table->string('login_method', 20);
            $table->char('review_token_hash', 64)->unique();
            $table->timestamp('review_expires_at');
            $table->timestamp('alert_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_activities');
        Schema::dropIfExists('recognized_login_devices');
    }
};
