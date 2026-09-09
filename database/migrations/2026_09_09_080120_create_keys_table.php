<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('keys', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('sku');
            $table->string('status')->default('available');
            $table->string('order_id')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            
            $table->index(['sku', 'status']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keys');
    }
};
