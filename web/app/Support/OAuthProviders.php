<?php

namespace App\Support;

final class OAuthProviders
{
    public const YANDEX = 'yandex';

    public const VK = 'vkontakte';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::YANDEX, self::VK];
    }

    public static function label(string $provider): string
    {
        return match ($provider) {
            self::YANDEX => 'Яндекс ID',
            self::VK => 'VK ID',
            default => $provider,
        };
    }

    public static function isConfigured(string $provider): bool
    {
        $config = config("services.{$provider}");

        if (! is_array($config)) {
            return false;
        }

        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        $redirect = trim((string) ($config['redirect'] ?? ''));

        return $clientId !== '' && $clientSecret !== '' && $redirect !== '';
    }

    /**
     * Ключи для фронта / роутов: yandex, vk.
     *
     * @return array{yandex: bool, vk: bool}
     */
    public static function configuredMap(): array
    {
        return [
            'yandex' => self::isConfigured(self::YANDEX),
            'vk' => self::isConfigured(self::VK),
        ];
    }

    public static function driver(string $provider): ?string
    {
        return in_array($provider, self::all(), true) ? $provider : null;
    }
}
