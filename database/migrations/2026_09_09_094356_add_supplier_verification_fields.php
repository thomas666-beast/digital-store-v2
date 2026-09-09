<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('key_verified')->default(false);
            $table->timestamp('key_verified_at')->nullable();
            $table->integer('verification_attempts')->default(0);
            $table->json('verification_results')->nullable();
        });

        Schema::table('keys', function (Blueprint $table) {
            $table->json('supplier_responses')->nullable();
            $table->integer('used_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['key_verified', 'key_verified_at', 'verification_attempts', 'verification_results']);
        });

        Schema::table('keys', function (Blueprint $table) {
            $table->dropColumn(['supplier_responses', 'used_count']);
        });
    }
};
