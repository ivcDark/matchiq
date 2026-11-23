<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\OAuthProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialAuthController extends Controller
{
    public function redirect(string $provider): RedirectResponse
    {
        $driver = $this->resolveDriver($provider);

        if ($driver === null) {
            abort(404);
        }

        if (! OAuthProviders::isConfigured($driver)) {
            return redirect()
                ->route('login')
                ->with('status', 'Вход через '.OAuthProviders::label($driver).' пока не настроен');
        }

        try {
            return Socialite::driver($driver)->redirect();
        } catch (Throwable) {
            return redirect()
                ->route('login')
                ->with('status', 'Вход через '.OAuthProviders::label($driver).' пока не настроен');
        }
    }

    public function callback(string $provider): RedirectResponse
    {
        $driver = $this->resolveDriver($provider);

        if ($driver === null) {
            abort(404);
        }

        if (! OAuthProviders::isConfigured($driver)) {
            return redirect()
                ->route('login')
                ->with('status', 'Вход через '.OAuthProviders::label($driver).' пока не настроен');
        }

        try {
            $oauthUser = Socialite::driver($driver)->user();
        } catch (Throwable) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Не удалось войти через '.OAuthProviders::label($driver).'. Попробуйте ещё раз.']);
        }

        $providerId = (string) $oauthUser->getId();
        $email = $oauthUser->getEmail();
        $name = trim((string) ($oauthUser->getName() ?: $oauthUser->getNickname() ?: ''));
        $avatar = $oauthUser->getAvatar();

        $account = SocialAccount::query()
            ->where('provider', $driver)
            ->where('provider_id', $providerId)
            ->first();

        if ($account) {
            Auth::login($account->user, remember: true);

            return redirect()->intended(route('matches.index', absolute: false));
        }

        $user = DB::transaction(function () use ($driver, $providerId, $email, $name, $avatar) {
            $user = null;

            if (is_string($email) && $email !== '') {
                $user = User::query()->where('email', Str::lower($email))->first();
            }

            if (! $user) {
                $nick = $this->uniqueNickname($name !== '' ? $name : 'Игрок');
                $user = User::create([
                    'name' => $nick,
                    'email' => $this->resolveEmail($email, $driver, $providerId),
                    'password' => Str::password(32), // cast `hashed` на User
                ]);
            }

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $driver,
                'provider_id' => $providerId,
                'avatar' => is_string($avatar) ? $avatar : null,
            ]);

            return $user;
        });

        Auth::login($user, remember: true);

        return redirect()->intended(route('matches.index', absolute: false));
    }

    private function resolveDriver(string $provider): ?string
    {
        return match ($provider) {
            'yandex' => OAuthProviders::YANDEX,
            'vk' => OAuthProviders::VK,
            default => null,
        };
    }

    private function resolveEmail(?string $email, string $provider, string $providerId): string
    {
        if (is_string($email) && $email !== '') {
            return Str::lower($email);
        }

        return $provider.'.'.$providerId.'@oauth.matchiq.local';
    }

    private function uniqueNickname(string $base): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $base) ?? '') ?: 'Игрок';
        $clean = Str::limit($clean, 40, '');
        $candidate = $clean;
        $i = 1;

        while (User::query()->where('name', $candidate)->exists()) {
            $suffix = ' '.$i;
            $candidate = Str::limit($clean, 40 - mb_strlen($suffix), '').$suffix;
            $i++;
        }

        return $candidate;
    }
}
