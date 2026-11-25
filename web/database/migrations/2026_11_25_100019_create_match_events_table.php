<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('related_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('type', 24);
            $table->smallInteger('minute');
            $table->smallInteger('added_minute')->nullable();
            $table->smallInteger('score_home')->nullable();
            $table->smallInteger('score_away')->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->jsonb('details')->nullable();
            $table->timestampsTz();

            $table->index(['match_id', 'sort_order']);
            $table->index('player_id');
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_events');
    }
};
