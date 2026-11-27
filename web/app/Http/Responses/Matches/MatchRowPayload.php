<?php

namespace App\Http\Responses\Matches;

use App\Domains\Matches\TeamForm;
use App\Enums\MarketCategory;
use App\Enums\MatchStatus;
use App\Models\FootballMatch;
use App\Models\MatchPreview;
use App\Models\PredictionOutcome;

final class MatchRowPayload
{
    /** Сколько «лучших событий» уходит в строку матча и в «Матч дня». */
    private const TOP_MARKETS = 3;

    /** Длина превью в строке: полный текст — на странице матча. */
    private const PREVIEW_LIMIT = 400;

    /**
     * Строка списка матчей главной. Вероятности и события — только из текущего прогноза модели, иначе null / пустой список.
     * Лучшие события: популярные рынки (кроме 1X2) по убыванию вероятности исхода.
     *
     * @return array<string, mixed>
     */
    public static function make(FootballMatch $match, TeamForm $form, ?MatchPreview $preview = null): array
    {
        $locale = config('matchiq.ui_locale');
        $prediction = $match->currentPrediction;
        $pct = static fn ($p): ?int => $p === null ? null : (int) round(((float) $p) * 100);
        $showProbs = $prediction !== null && $match->status !== MatchStatus::Finished;
        $markets = $showProbs ? self::topMarkets($prediction->outcomes, (string) $locale) : [];

        return [
            'id' => $match->id,
            'league_id' => $match->competition->slug,
            'home' => $match->homeTeam->localized('name', $locale),
            'away' => $match->awayTeam->localized('name', $locale),
            'home_logo' => $match->homeTeam->logo_url,
            'away_logo' => $match->awayTeam->logo_url,
            'kickoff_at' => $match->kickoff_at->toIso8601String(),
            'status' => $match->status->value,
            'minute' => $match->minute,
            'score_home' => $match->home_goals,
            'score_away' => $match->away_goals,
            'form_home' => self::letters($form, $match->home_team_id, $match),
            'form_away' => self::letters($form, $match->away_team_id, $match),
            'prob_home' => $showProbs ? $pct($prediction->p_home) : null,
            'prob_draw' => $showProbs ? $pct($prediction->p_draw) : null,
            'prob_away' => $showProbs ? $pct($prediction->p_away) : null,
            'top_market' => $markets[0] ?? null,
            'top_markets' => $markets,
            'preview' => self::previewText($preview),
        ];
    }

    /**
     * @param  iterable<PredictionOutcome>  $outcomes
     * @return list<array{label: string, market: string, prob: int}>
     */
    private static function topMarkets(iterable $outcomes, string $locale): array
    {
        $rows = [];

        foreach ($outcomes as $outcome) {
            $type = $outcome->marketType;
            if ($type === null || ! $type->is_popular || $type->category === MarketCategory::Results) {
                continue;
            }

            $label = $type->outcomes->firstWhere('outcome_key', $outcome->outcome_key)?->localized('label', $locale)
                ?? $outcome->outcome_key;
            $line = $outcome->line === null ? '' : ' '.rtrim(rtrim((string) $outcome->line, '0'), '.');
            $name = (string) $type->localized('name', $locale);

            $rows[] = [
                'label' => $type->has_line ? trim($label.$line) : trim($name.': '.$label),
                'market' => $name,
                'prob' => (int) round(((float) $outcome->probability) * 100),
            ];
        }

        usort($rows, static fn (array $a, array $b) => $b['prob'] <=> $a['prob']);

        return array_slice($rows, 0, self::TOP_MARKETS);
    }

    private static function previewText(?MatchPreview $preview): ?string
    {
        $text = trim((string) $preview?->preview_text);

        return $text === '' ? null : mb_strimwidth($text, 0, self::PREVIEW_LIMIT, '…');
    }

    /** @return list<string> */
    public static function letters(TeamForm $form, int $teamId, FootballMatch $match): array
    {
        return array_map(
            static fn ($result) => $result->value,
            $form->results($teamId, $match->kickoff_at),
        );
    }
}
