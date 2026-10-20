<?php

namespace App\Providers;

use App\Accounts\CurrentAccount;
use App\Http\OpenApi\DocumentIdentity;
use App\Http\OpenApi\ProblemDocumentation;
use App\Http\OpenApi\SecurityDocumentation;
use App\Webhooks\Outbound\OutboundHostGuard;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    private const MIN_JWT_SECRET_BYTES = 32;

    /**
     * Settings read from the environment as integers, with the smallest value the application accepts. An empty or
     * non-numeric variable casts to 0, and 0 would turn every request into a 429 (a rate limit), every create into a
     * 422 (the duration ceiling) or make a release fire before the browser has closed the call (the delay), so the
     * application refuses to start instead.
     *
     * @var array<string, array{0: string, 1: int}> config key => [variable, minimum]
     */
    private const INTEGER_SETTINGS = [
        'hr-avatar.rate_limits.secret' => ['HRA_RATE_LIMIT_SECRET', 1],
        'hr-avatar.rate_limits.publishable' => ['HRA_RATE_LIMIT_PUBLISHABLE', 1],
        'hr-avatar.rate_limits.preview' => ['HRA_RATE_LIMIT_PREVIEW', 1],
        'hr-avatar.max_duration_ceiling' => ['HRA_MAX_DURATION_CEILING', 30],
        'hr-avatar.release_delay_seconds' => ['HRA_RELEASE_DELAY_SECONDS', 5],
    ];

    /** A minute's callbacks from one address. Fixed by the provider-webhooks spec, hence not configuration. */
    private const PROVIDER_WEBHOOK_PER_MINUTE = 120;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentAccount::class);

        // Webhook destinations are vetted everywhere except on a developer's machine and in tests.
        $this->app->singleton(OutboundHostGuard::class, fn (Application $app) => new OutboundHostGuard(! $app->environment('local', 'testing')));

        // The documentation UI lives outside /v1; the committed openapi.json is the published contract.
        Scramble::ignoreDefaultRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->guardIntegerSettings();
        $this->guardJwtSecret();

        // One budget per key and limiter, whichever kind of key it is: the authentication middleware records the key id.
        foreach (['secret', 'publishable', 'preview'] as $limiter) {
            RateLimiter::for($limiter, fn (Request $request) => Limit::perMinute((int) config("hr-avatar.rate_limits.{$limiter}"))
                ->by((string) ($request->attributes->get('api_key_id') ?? $request->ip())));
        }

        // Provider callbacks carry no key, so their budget is the caller's address.
        RateLimiter::for('provider-webhook', fn (Request $request) => Limit::perMinute(self::PROVIDER_WEBHOOK_PER_MINUTE)->by((string) $request->ip()));

        Scramble::configure()
            ->withDocumentTransformers(DocumentIdentity::apply(...))
            ->withDocumentTransformers(SecurityDocumentation::document(...))
            ->withDocumentTransformers(ProblemDocumentation::document(...))
            ->withOperationTransformers(SecurityDocumentation::operation(...));

        Event::listen(RouteMatched::class, fn (RouteMatched $event) => Log::shareContext(['route' => $event->route->getName()]));
    }

    /**
     * Refuse to run with a limit or a duration below its minimum. The message names the variable, never a value.
     */
    private function guardIntegerSettings(): void
    {
        foreach (self::INTEGER_SETTINGS as $key => [$variable, $minimum]) {
            $value = config($key);

            if (! is_int($value) || $value < $minimum) {
                throw new RuntimeException("{$variable} must be a whole number of at least {$minimum}.");
            }
        }
    }

    /**
     * Refuse to run with a weak signing secret. The message names the variable, never a value.
     */
    private function guardJwtSecret(): void
    {
        if ($this->app->environment('testing')) {
            return;
        }

        $secret = config('hr-avatar.jwt_secret');

        if (! is_string($secret) || strlen($secret) < self::MIN_JWT_SECRET_BYTES) {
            throw new RuntimeException(
                'HRA_JWT_SECRET must be set to at least '.self::MIN_JWT_SECRET_BYTES.' bytes.'
            );
        }
    }
}
