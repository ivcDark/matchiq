<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('tie_number');
            $table->foreignId('team_a_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('team_b_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->jsonb('source_a')->nullable();
            $table->jsonb('source_b')->nullable();
            $table->smallInteger('legs')->default(1);
            $table->string('status', 16)->default('scheduled');
            $table->foreignId('winner_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('decided_by', 16)->nullable();
            $table->smallInteger('aggregate_a')->nullable();
            $table->smallInteger('aggregate_b')->nullable();
            $table->smallInteger('penalties_a')->nullable();
            $table->smallInteger('penalties_b')->nullable();
            $table->foreignId('next_tie_id')->nullable()->constrained('ties')->nullOnDelete();
            $table->string('next_tie_slot', 1)->nullable();
            $table->timestampsTz();

            $table->unique(['round_id', 'tie_number']);
            $table->index('stage_id');
            $table->index('team_a_id');
            $table->index('team_b_id');
            $table->index('next_tie_id');
        });

        PgSchema::check('ties', 'ties_legs_valid', 'legs IN (1, 2)');
        PgSchema::check('ties', 'ties_next_tie_slot_valid', "next_tie_slot IS NULL OR next_tie_slot IN ('a', 'b')");
    }

    public function down(): void
    {
        Schema::dropIfExists('ties');
    }
};
