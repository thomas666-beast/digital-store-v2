<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->string('sku')->primary();
            $table->string('name');
            $table->string('type');
            $table->integer('price');
            $table->string('currency', 3)->default('RUB');
            $table->string('image')->nullable();
            $table->integer('stock')->default(0);
            $table->integer('sales_count')->default(0);
            $table->float('rating')->default(0);
            $table->timestamps();
            
            $table->index(['type', 'price']);
            $table->index(['price', 'stock']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
