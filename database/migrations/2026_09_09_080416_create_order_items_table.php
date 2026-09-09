<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->string('order_id');
            $table->string('sku');
            $table->integer('quantity')->default(1);
            $table->integer('unit_price');
            $table->integer('total_price');
            $table->string('currency', 3)->default('RUB');
            $table->string('status')->default('pending');
            $table->string('key_code')->nullable();
            $table->string('supplier_used')->nullable();
            $table->string('delivery_request_id')->nullable();
            $table->integer('retry_count')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->index(['order_id', 'status']);
            $table->index(['sku', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
