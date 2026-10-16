<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 32)->default('draft')->index();
            $table->unsignedInteger('inventory')->default(0);
            $table->string('category')->default('Uncategorized');
            $table->unsignedSmallInteger('channels')->default(0);
            $table->string('product_type');
            $table->string('vendor');
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
