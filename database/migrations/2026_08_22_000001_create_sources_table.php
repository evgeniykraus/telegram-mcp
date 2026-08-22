<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 32)->default('telegram');
            $table->string('username', 128);
            $table->string('title', 256)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('subscribers_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_own')->default(false);
            $table->string('category', 64)->nullable();
            $table->timestamp('last_collected_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
