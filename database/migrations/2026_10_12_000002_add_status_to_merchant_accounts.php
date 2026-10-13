<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 32)->default('active')->index();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by_platform_admin_id')->nullable()
                ->constrained('platform_admins')->nullOnDelete();
        });

        Schema::table('platform_admin_audit_logs', function (Blueprint $table) {
            $table->foreignId('merchant_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->index(['merchant_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('platform_admin_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['merchant_user_id', 'created_at']);
            $table->dropConstrainedForeignId('merchant_user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suspended_by_platform_admin_id');
            $table->dropColumn(['status', 'suspended_at']);
        });
    }
};
