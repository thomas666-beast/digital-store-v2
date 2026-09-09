<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->string('order_id');
            $table->string('event_type');
            $table->json('payload');
            $table->json('snapshot')->nullable();
            $table->timestamp('event_time')->useCurrent();
            $table->integer('version')->default(1);
            $table->timestamps();
            
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->index(['order_id', 'event_time']);
            $table->index(['order_id', 'version']);
            $table->index('event_type');
        });

        Schema::create('financial_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('order_id');
            $table->integer('balance');
            $table->integer('total_delivered');
            $table->integer('total_refunded');
            $table->timestamp('snapshot_time')->useCurrent();
            $table->integer('event_version')->default(0);
            $table->timestamps();
            
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->index(['order_id', 'snapshot_time']);
        });

        Schema::create('period_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('period_type'); // daily, weekly, monthly
            $table->date('period_date');
            $table->integer('total_orders');
            $table->integer('total_revenue');
            $table->integer('total_refunds');
            $table->integer('net_revenue');
            $table->integer('delivered_count');
            $table->integer('failed_count');
            $table->integer('refunded_count');
            $table->json('top_products')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
            
            $table->unique(['period_type', 'period_date']);
            $table->index(['period_type', 'period_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_summaries');
        Schema::dropIfExists('financial_snapshots');
        Schema::dropIfExists('order_events');
    }
};
