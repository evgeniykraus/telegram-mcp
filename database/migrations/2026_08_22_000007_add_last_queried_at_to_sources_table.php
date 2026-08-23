<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->timestamp('last_queried_at')->nullable()->index()->after('last_collected_at');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropIndex(['last_queried_at']);
            $table->dropColumn('last_queried_at');
        });
    }
};
