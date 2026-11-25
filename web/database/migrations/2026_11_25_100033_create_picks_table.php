<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('picks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->constrained();
            $table->foreignId('prediction_outcome_id')->constrained('prediction_outcomes');
            $table->foreignId('market_type_id')->constrained();
            $table->string('outcome_key', 32);
            $table->decimal('line', 5, 2)->nullable();
            $table->decimal('probability_at_pick', 5, 4);
            $table->string('status', 16)->default('pending');
            $table->smallInteger('points')->default(0);
            $table->timestampTz('picked_at');
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index('prediction_outcome_id');
            $table->index('market_type_id');
        });

        PgSchema::index('picks', 'picks_active_per_user_match_unique', 'user_id, match_id', unique: true, where: 'superseded_at IS NULL');
        PgSchema::index('picks', 'picks_user_history_index', 'user_id, picked_at DESC');
        PgSchema::index('picks', 'picks_pending_match_index', 'match_id', where: "status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('picks');
    }
};
