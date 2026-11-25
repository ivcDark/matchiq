<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_lineups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained();
            $table->foreignId('player_id')->constrained();
            $table->boolean('is_starter')->default(false);
            $table->boolean('is_captain')->default(false);
            $table->smallInteger('shirt_number')->nullable();
            $table->string('position', 2)->nullable();
            $table->string('line', 16)->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->smallInteger('minute_in')->nullable();
            $table->smallInteger('minute_out')->nullable();
            $table->timestampsTz();

            $table->unique(['match_id', 'player_id']);
            $table->index(['match_id', 'team_id', 'is_starter']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_lineups');
    }
};
