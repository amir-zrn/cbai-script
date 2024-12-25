<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 64)->unique();
            $table->integer('wp_user_id');
            $table->integer('total_tokens_allocated');
            $table->integer('tokens_used')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('api_key');
            $table->index('wp_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
