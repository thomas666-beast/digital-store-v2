<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('sku')->nullable();
            $table->integer('amount');
            $table->string('currency', 3)->default('RUB');
            $table->string('status')->default('created');
            $table->string('type')->default('single');
            $table->string('key_code')->nullable();
            $table->string('payment_event_id')->nullable()->unique();
            $table->integer('total_delivered_value')->default(0);
            $table->integer('total_refunded')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index('status');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
