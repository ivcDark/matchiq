<?php

namespace App\Enums;

/**
 * Значения = job_type сообщений ingest и `ingestion_runs.kind` (varchar(32)).
 */
enum IngestionKind: string
{
    case Schedule = 'schedule';
    case MatchStats = 'match_stats';
    case Lineups = 'lineups';
    case Live = 'live';
    /** Страны, соревнования и сезоны по фильтрам. */
    case SyncCatalog = 'sync_catalog';
    /** Стадии и команды одного сезона. */
    case SyncStructure = 'sync_structure';
    /** Расписание и результаты матчей сезона/стадии за окно дат. */
    case SyncFixtures = 'sync_fixtures';
    /** Составы, события, командная и игровая статистика выбранных матчей. */
    case SyncMatchDetails = 'sync_match_details';
}
