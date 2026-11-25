<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaderboard_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period_type', 16);
            $table->string('period_key', 16);
            $table->foreignId('competition_id')->nullable()->constrained()->cascadeOnDelete();
            $table->integer('rank');
            $table->integer('points')->default(0);
            $table->integer('picks_count')->default(0);
            $table->integer('correct_count')->default(0);
            $table->smallInteger('streak')->default(0);
            $table->timestampTz('computed_at')->nullable();
            $table->timestampsTz();

            $table->index('competition_id');
        });

        PgSchema::index(
            'leaderboard_entries',
            'leaderboard_entries_slot_unique',
            'period_type, period_key, competition_id, user_id',
            unique: true,
            nullsNotDistinct: true,
        );
        PgSchema::index(
            'leaderboard_entries',
            'leaderboard_entries_top_index',
            'period_type, period_key, competition_id, rank',
            include: 'points, picks_count, correct_count, streak',
        );
        PgSchema::index('leaderboard_entries', 'leaderboard_entries_user_index', 'user_id, period_type, period_key');
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_entries');
    }
};
