<?php

namespace App\Providers;

use App\Accounts\CurrentAccount;
use App\Http\OpenApi\DocumentIdentity;
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
        $this->guardJwtSecret();

        // One budget per key and limiter, whichever kind of key it is: the authentication middleware records the key id.
        foreach (['secret', 'publishable', 'preview'] as $limiter) {
            RateLimiter::for($limiter, fn (Request $request) => Limit::perMinute((int) config("hr-avatar.rate_limits.{$limiter}"))
                ->by((string) ($request->attributes->get('api_key_id') ?? $request->ip())));
        }

        Scramble::configure()
            ->withDocumentTransformers(DocumentIdentity::apply(...))
            ->withDocumentTransformers(SecurityDocumentation::document(...))
            ->withOperationTransformers(SecurityDocumentation::operation(...));

        Event::listen(RouteMatched::class, fn (RouteMatched $event) => Log::shareContext(['route' => $event->route->getName()]));
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
