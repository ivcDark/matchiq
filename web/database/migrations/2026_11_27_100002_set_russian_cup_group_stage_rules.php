<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rules = config('matchiq.stage_rules.russian-cup');
        if (! is_array($rules)) {
            return;
        }

        $seasonIds = DB::table('seasons')
            ->whereIn('competition_id', DB::table('competitions')->where('slug', 'russian-cup')->select('id'))
            ->pluck('id');

        DB::table('stages')
            ->whereIn('season_id', $seasonIds)
            ->whereIn('type', ['group', 'league'])
            ->get(['id', 'rules'])
            ->filter(fn ($stage) => (json_decode((string) $stage->rules, true) ?: []) === [])
            ->each(fn ($stage) => DB::table('stages')->where('id', $stage->id)->update(['rules' => json_encode($rules)]));
    }

    public function down(): void
    {
        $rules = config('matchiq.stage_rules.russian-cup');

        DB::table('stages')->get(['id', 'rules'])
            ->filter(fn ($stage) => json_decode((string) $stage->rules, true) === $rules)
            ->each(fn ($stage) => DB::table('stages')->where('id', $stage->id)->update(['rules' => '{}']));
    }
};
