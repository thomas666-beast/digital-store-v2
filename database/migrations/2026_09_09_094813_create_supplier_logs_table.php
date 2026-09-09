<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('supplier_logs')) {
            Schema::create('supplier_logs', function (Blueprint $table) {
                $table->id();
                $table->string('request_id');
                $table->string('order_id');
                $table->string('sku');
                $table->string('supplier');
                $table->string('status');
                $table->string('key_code')->nullable();
                $table->integer('attempt')->default(1);
                $table->text('error_message')->nullable();
                $table->integer('response_time')->nullable();
                $table->timestamps();
                
                $table->index('request_id');
                $table->index('order_id');
                $table->index('supplier');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_logs');
    }
};
