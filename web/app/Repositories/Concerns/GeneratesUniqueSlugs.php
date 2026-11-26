<?php

namespace App\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

trait GeneratesUniqueSlugs
{
    /**
     * @param  list<string>  $hints  Дополнительные части (код страны), пробуются раньше числового суффикса.
     */
    protected function uniqueSlug(Builder $query, string $name, array $hints = []): string
    {
        $base = Str::slug($name) ?: 'item';
        $candidates = [$base];
        foreach ($hints as $hint) {
            if ($hint !== '') {
                $candidates[] = Str::slug($name.' '.$hint);
            }
        }

        foreach ($candidates as $candidate) {
            if (! (clone $query)->where('slug', $candidate)->exists()) {
                return $candidate;
            }
        }

        for ($i = 2; ; $i++) {
            $candidate = $base.'-'.$i;
            if (! (clone $query)->where('slug', $candidate)->exists()) {
                return $candidate;
            }
        }
    }
}
