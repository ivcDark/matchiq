<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Каноническое EN-значение в `{field}`, переводы — в jsonb `{field}_i18n` ({"ru": "Зенит"}).
 * Чтение: перевод локали → базовый язык (pt-br → pt) → каноническое значение.
 *
 * @mixin Model
 */
trait HasI18nNames
{
    public function localized(string $field = 'name', ?string $locale = null): ?string
    {
        $locale = strtolower($locale ?? app()->getLocale());
        $translations = $this->getAttribute($field.'_i18n');

        if (is_array($translations) && $translations !== []) {
            $base = explode('-', $locale)[0];

            foreach (array_unique([$locale, $base]) as $candidate) {
                $value = $translations[$candidate] ?? null;

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return $this->getAttribute($field);
    }

    /**
     * Точечная запись перевода без перезаписи остальных ключей. Ключ `en` не пишется.
     */
    public function setTranslation(string $field, string $locale, string $value): static
    {
        $locale = strtolower($locale);

        if ($locale === 'en') {
            return $this;
        }

        $translations = $this->getAttribute($field.'_i18n') ?? [];
        $translations[$locale] = $value;
        $this->setAttribute($field.'_i18n', $translations);

        return $this;
    }
}
