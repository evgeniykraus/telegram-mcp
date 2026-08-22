<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('posts_fetched')->default(0);
            $table->unsignedInteger('posts_saved')->default(0);
            $table->string('status', 16)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_runs');
    }
};
