<?php

namespace App\Providers;

use App\Accounts\CurrentAccount;
use App\Http\OpenApi\DocumentIdentity;
use App\Http\OpenApi\SecurityDocumentation;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
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

        // The documentation UI lives outside /v1; the committed openapi.json is the published contract.
        Scramble::ignoreDefaultRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->guardJwtSecret();

        RateLimiter::for('secret', fn (Request $request) => Limit::perMinute((int) config('hr-avatar.rate_limits.secret'))
            ->by((string) ($request->attributes->get('api_key_id') ?? $request->ip())));

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
