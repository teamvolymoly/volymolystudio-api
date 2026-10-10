<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recognized_login_devices', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->index();
        });

        Schema::table('login_activities', function (Blueprint $table) {
            $table->timestamp('secured_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('login_activities', function (Blueprint $table) {
            $table->dropColumn('secured_at');
        });

        Schema::table('recognized_login_devices', function (Blueprint $table) {
            $table->dropColumn('revoked_at');
        });
    }
};
