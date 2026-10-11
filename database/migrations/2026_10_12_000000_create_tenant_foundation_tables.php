<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 32)->default('active')->index();
            $table->foreignId('created_by_platform_admin_id')->nullable()
                ->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('member');
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['organization_id', 'role', 'status']);
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 32)->default('active')->index();
            $table->char('currency', 3)->default('INR');
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->foreignId('created_by_platform_admin_id')->nullable()
                ->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('store_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('staff');
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['store_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['store_id', 'role', 'status']);
        });

        Schema::create('platform_admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_admin_id')->nullable()
                ->constrained('platform_admins')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->string('action', 96)->index();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['platform_admin_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_audit_logs');
        Schema::dropIfExists('store_memberships');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('organizations');
    }
};
