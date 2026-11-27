<?php

namespace App\Enums;

enum ExternalEntityType: string
{
    case Country = 'country';
    case Competition = 'competition';
    case Season = 'season';
    case Stage = 'stage';
    case Round = 'round';
    /** Группа стадии и участие команды в стадии: ключуются по (stage, name) и (stage, team), в external_ids не пишутся. */
    case StageGroup = 'stage_group';
    case StageTeam = 'stage_team';
    case Tie = 'tie';
    case Team = 'team';
    case Venue = 'venue';
    case Player = 'player';
    case Coach = 'coach';
    case Referee = 'referee';
    case Match = 'match';
    /** Составы, события и статистика одного матча; external_id = внешний id матча. В external_ids не пишется. */
    case MatchDetails = 'match_details';
    /** Актуальный состав клуба; external_id = внешний id команды. В external_ids не пишется. */
    case Squad = 'squad';
    case MarketType = 'market_type';
    /** Дополнительные идентификаторы (sstats_api, flashscore, pari) уже известной сущности; external_id = её id у провайдера sstats. В external_ids не пишется. */
    case ExternalRefs = 'external_refs';
}
