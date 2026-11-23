<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_yandex_redirects_with_message(): void
    {
        config([
            'services.yandex.client_id' => '',
            'services.yandex.client_secret' => '',
            'services.yandex.redirect' => '',
        ]);

        $this->get('/auth/yandex')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Вход через Яндекс ID пока не настроен');
    }

    public function test_unconfigured_vk_redirects_with_message(): void
    {
        config([
            'services.vkontakte.client_id' => '',
            'services.vkontakte.client_secret' => '',
            'services.vkontakte.redirect' => '',
        ]);

        $this->get('/auth/vk')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Вход через VK ID пока не настроен');
    }

    public function test_unknown_oauth_provider_is_not_found(): void
    {
        $this->get('/auth/google')->assertNotFound();
    }
}
