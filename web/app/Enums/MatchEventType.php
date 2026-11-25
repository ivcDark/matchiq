<?php

namespace App\Enums;

enum MatchEventType: string
{
    case Goal = 'goal';
    case OwnGoal = 'own_goal';
    case PenaltyGoal = 'penalty_goal';
    case PenaltyMissed = 'penalty_missed';
    case Yellow = 'yellow';
    case SecondYellow = 'second_yellow';
    case Red = 'red';
    case Substitution = 'substitution';
    case Var = 'var';
}
