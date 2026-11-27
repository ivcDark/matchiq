<?php

namespace App\Enums;

/**
 * `source_providers.code`.
 *
 * `sstats` — ключ ingest: id Ls-эндпоинтов SStats (у матчей, команд и лиг это flash-id). Остальные коды — дополнительные
 * идентификаторы той же сущности (`external_refs`): числовой id SStats API (`/Games`), flashscore и pari.
 */
enum SourceProviderCode: string
{
    case Sstats = 'sstats';
    case SstatsApi = 'sstats_api';
    case Flashscore = 'flashscore';
    case Pari = 'pari';

    public function label(): string
    {
        return match ($this) {
            self::Sstats => 'SStats.net',
            self::SstatsApi => 'SStats.net (числовые id /Games)',
            self::Flashscore => 'Flashscore',
            self::Pari => 'Pari',
        };
    }

    /** Провайдеры дополнительных идентификаторов (всё, кроме ключа ingest). */
    public function isReference(): bool
    {
        return $this !== self::Sstats;
    }
}
