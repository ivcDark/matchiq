<?php

namespace App\Domains\Tournaments;

use App\Enums\RoundCode;
use App\Enums\RoundType;
use App\Models\Round;
use App\Models\Stage;
use App\Models\StageGroup;

/** Подпись тура или раунда для клуба: «3 тур», «Группа A · 2 тур», «1/8 финала». */
final class RoundLabel
{
    public static function code(RoundCode $code): string
    {
        return match ($code) {
            RoundCode::R64 => '1/32 финала',
            RoundCode::R32 => '1/16 финала',
            RoundCode::R16 => '1/8 финала',
            RoundCode::QuarterFinal => 'Четвертьфинал',
            RoundCode::SemiFinal => 'Полуфинал',
            RoundCode::ThirdPlace => 'Матч за 3-е место',
            RoundCode::Final => 'Финал',
            RoundCode::Playoff => 'Плей-офф',
        };
    }

    public static function label(?Round $round, ?StageGroup $group = null, ?Stage $stage = null): string
    {
        if ($round?->code !== null) {
            return self::code($round->code);
        }

        $name = trim((string) $round?->name);
        $number = $round?->number === null ? '' : $round->number.' тур';
        $head = $group?->name ?? '';

        if ($name !== '' && ($number === '' || $round?->type === RoundType::KnockoutRound)) {
            return trim($head === '' ? $name : $head.' · '.$name);
        }

        $tour = $number !== '' ? $number : ($head === '' && $stage !== null ? (string) $stage->name : '');

        return $head !== '' && $tour !== '' ? $head.' · '.$tour : ($head !== '' ? $head : $tour);
    }
}
