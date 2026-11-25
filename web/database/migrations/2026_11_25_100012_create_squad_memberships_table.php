<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('squad_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('shirt_number')->nullable();
            $table->string('position', 2)->nullable();
            $table->date('joined_on')->nullable();
            $table->date('left_on')->nullable();
            $table->string('kind', 16)->default('permanent');
            $table->timestampsTz();

            $table->index(['team_id', 'left_on']);
        });

        PgSchema::index('squad_memberships', 'squad_memberships_player_career_index', 'player_id, joined_on DESC');
    }

    public function down(): void
    {
        Schema::dropIfExists('squad_memberships');
    }
};
