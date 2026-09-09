<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_rate_limits', function (Blueprint $table) {
            $table->id();
            $table->string('supplier')->default('mock');
            $table->integer('max_requests_per_minute')->default(10);
            $table->integer('current_requests')->default(0);
            $table->timestamp('reset_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_queues', function (Blueprint $table) {
            $table->id();
            $table->string('order_id');
            $table->string('sku');
            $table->integer('priority')->default(0); // Higher = higher priority
            $table->string('status')->default('queued'); // queued, processing, completed, failed
            $table->integer('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->index(['status', 'priority', 'queued_at']);
            $table->index(['order_id', 'status']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_queued')->default(false);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->integer('queue_position')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_queue');
        Schema::dropIfExists('supplier_rate_limits');
        
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['is_queued', 'queued_at', 'fulfilled_at', 'queue_position']);
        });
    }
};
