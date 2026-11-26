<?php

namespace App\Providers;

use App\Enums\ExternalEntityType;
use App\Models\Coach;
use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\MarketType;
use App\Models\Player;
use App\Models\Referee;
use App\Models\Round;
use App\Models\Season;
use App\Models\Stage;
use App\Models\Team;
use App\Models\Tie;
use App\Models\Venue;
use App\Services\Ingestion\Messaging\AmqpIngestionBroker;
use App\Services\Ingestion\Messaging\CommandPublisher;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Yandex\Provider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AmqpIngestionBroker::class);
        $this->app->bind(CommandPublisher::class, AmqpIngestionBroker::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Relation::morphMap([
            ExternalEntityType::Country->value => Country::class,
            ExternalEntityType::Competition->value => Competition::class,
            ExternalEntityType::Season->value => Season::class,
            ExternalEntityType::Stage->value => Stage::class,
            ExternalEntityType::Round->value => Round::class,
            ExternalEntityType::Tie->value => Tie::class,
            ExternalEntityType::Team->value => Team::class,
            ExternalEntityType::Venue->value => Venue::class,
            ExternalEntityType::Player->value => Player::class,
            ExternalEntityType::Coach->value => Coach::class,
            ExternalEntityType::Referee->value => Referee::class,
            ExternalEntityType::Match->value => FootballMatch::class,
            ExternalEntityType::MarketType->value => MarketType::class,
        ]);

        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('yandex', Provider::class);
            $event->extendSocialite('vkontakte', \SocialiteProviders\VKontakte\Provider::class);
        });
    }
}
