<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        $dim = (int) config('analytics.embeddings.dimension', 1024);

        if ($driver === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector;');
            DB::statement("ALTER TABLE posts ADD COLUMN IF NOT EXISTS embedding vector({$dim});");
            DB::statement('CREATE INDEX IF NOT EXISTS posts_embedding_hnsw_idx ON posts USING hnsw (embedding vector_cosine_ops);');
        } else {
            Schema::table('posts', function (Blueprint $table) {
                $table->json('embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS posts_embedding_hnsw_idx;');
            DB::statement('ALTER TABLE posts DROP COLUMN IF EXISTS embedding;');
        } else {
            Schema::table('posts', function (Blueprint $table) {
                $table->dropColumn('embedding');
            });
        }
    }
};
