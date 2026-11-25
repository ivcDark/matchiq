<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_provider_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->string('locale', 10)->nullable();
            $table->timestampsTz();

            $table->index('alias');
            $table->index('source_provider_id');
        });

        PgSchema::index(
            'team_aliases',
            'team_aliases_slot_unique',
            'team_id, alias, source_provider_id',
            unique: true,
            nullsNotDistinct: true,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('team_aliases');
    }
};
