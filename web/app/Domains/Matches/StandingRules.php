<?php

namespace App\Domains\Matches;

/**
 * Очки за результат матча этапа. Хранится в `stages.rules.points`:
 * {"win": 3, "draw": 1, "loss": 0, "penalty_win": 2, "penalty_loss": 1}.
 *
 * Без ключей `penalty_*` ничья по счёту остаётся ничьей, даже если матч решили пенальти.
 * С ними ничья в основное время (с доп. временем или без) делится по серии: победа по пенальти и поражение по пенальти.
 */
final class StandingRules
{
    public function __construct(
        public readonly int $win = 3,
        public readonly int $draw = 1,
        public readonly int $loss = 0,
        public readonly ?int $penaltyWin = null,
        public readonly ?int $penaltyLoss = null,
    ) {}

    /** @param  array<string, mixed>|null  $stageRules  значение `stages.rules` */
    public static function fromStageRules(?array $stageRules): self
    {
        $points = $stageRules['points'] ?? null;
        if (! is_array($points)) {
            return new self;
        }

        $get = static fn (string $key, ?int $default): ?int => isset($points[$key]) && is_numeric($points[$key]) ? (int) $points[$key] : $default;

        return new self(
            win: $get('win', 3),
            draw: $get('draw', 1),
            loss: $get('loss', 0),
            penaltyWin: $get('penalty_win', null),
            penaltyLoss: $get('penalty_loss', null),
        );
    }

    /** Очки команды за матч; пенальти учитываются только при ничьей по счёту. */
    public function points(int $for, int $against, ?int $pensFor, ?int $pensAgainst): int
    {
        if ($for !== $against) {
            return $for > $against ? $this->win : $this->loss;
        }

        if (! $this->decidedByShootout($pensFor, $pensAgainst)) {
            return $this->draw;
        }

        return $pensFor > $pensAgainst ? $this->penaltyWin : $this->penaltyLoss;
    }

    public function hasPenaltyPoints(): bool
    {
        return $this->penaltyWin !== null && $this->penaltyLoss !== null;
    }

    /** Ничья по счёту, решённая серией: очки по ней идут как за победу/поражение по пенальти. */
    public function decidedByShootout(?int $pensFor, ?int $pensAgainst): bool
    {
        return $this->hasPenaltyPoints() && $pensFor !== null && $pensAgainst !== null && $pensFor !== $pensAgainst;
    }

    /** @return array{win: int, draw: int, loss: int, penalty_win: ?int, penalty_loss: ?int} */
    public function toArray(): array
    {
        return [
            'win' => $this->win,
            'draw' => $this->draw,
            'loss' => $this->loss,
            'penalty_win' => $this->penaltyWin,
            'penalty_loss' => $this->penaltyLoss,
        ];
    }
}
