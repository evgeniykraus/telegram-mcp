<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->timestamp('published_at')->nullable()->index();
            $table->text('text')->nullable();
            $table->string('format_type', 32)->nullable()->index();
            $table->text('url')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('forwards')->default(0);
            $table->unsignedInteger('reactions')->default(0);
            $table->unsignedInteger('comments')->default(0);
            $table->jsonb('reactions_breakdown')->nullable();
            $table->float('er')->default(0)->index();
            $table->float('content_score')->default(0)->index();
            $table->boolean('is_ad')->default(false)->index();
            $table->string('forward_from_chat_id', 64)->nullable();
            $table->jsonb('raw_metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'external_id']);
            $table->index(['published_at', 'er']);
            $table->index(['source_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
