<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Флаги ingest отдельно от витринного `competitions.status`:
 * соревнование грузится планировщиком, только если `ingest_enabled = true`; команда — исключается из составов при `false`.
 */
return new class extends Migration
{
    private const ENABLED_SLUGS = ['premier-league', 'russian-cup'];

    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->boolean('ingest_enabled')->default(false)->after('status');
            $table->index('ingest_enabled');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('ingest_enabled')->default(true)->after('is_active');
        });

        DB::table('competitions')->whereIn('slug', self::ENABLED_SLUGS)->update(['ingest_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('ingest_enabled');
        });

        Schema::table('competitions', function (Blueprint $table) {
            $table->dropIndex(['ingest_enabled']);
            $table->dropColumn('ingest_enabled');
        });
    }
};
