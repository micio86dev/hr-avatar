<?php

use App\Http\Controllers\V1\ApiKeyController;
use App\Http\Controllers\V1\AvatarConfigController;
use App\Http\Controllers\V1\CatalogueController;
use App\Http\Controllers\V1\Client\ClientConversationController;
use App\Http\Controllers\V1\ConversationController;
use App\Http\Controllers\V1\HealthController;
use App\Http\Controllers\V1\LlmCredentialController;
use App\Http\Controllers\V1\LlmModelController;
use App\Http\Controllers\V1\ProviderCredentialController;
use App\Http\Controllers\V1\VoicePreviewController;
use App\Http\Controllers\V1\WebhookDeliveryController;
use App\Http\Controllers\V1\WebhookEndpointController;
use App\Http\Middleware\RejectKeyInQuery;
use Illuminate\Support\Facades\Route;

// All /v1 routes are registered here, grouped by authentication chain.

Route::get('health', [HealthController::class, 'live'])->name('health.live');
Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');

// Secret-key routes: the key is read from the Authorization header only, then rate limited per key.
Route::middleware([RejectKeyInQuery::class, 'auth.secret', 'throttle:secret'])->group(function () {
    Route::middleware('scope:api_keys:manage')->group(function () {
        Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
        Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
        Route::post('api-keys/{apiKey}/revoke', [ApiKeyController::class, 'revoke'])->name('api-keys.revoke');
        Route::post('api-keys/{apiKey}/rotate', [ApiKeyController::class, 'rotate'])->name('api-keys.rotate');
    });

    Route::middleware('scope:credentials:manage')->group(function () {
        Route::get('provider-credentials', [ProviderCredentialController::class, 'index'])->name('provider-credentials.index');
        Route::put('provider-credentials/{provider}', [ProviderCredentialController::class, 'update'])->whereIn('provider', config()->array('hr-avatar.credential_providers'))->name('provider-credentials.update');
        Route::delete('provider-credentials/{provider}', [ProviderCredentialController::class, 'destroy'])->whereIn('provider', config()->array('hr-avatar.credential_providers'))->name('provider-credentials.destroy');

        Route::get('llm-credentials', [LlmCredentialController::class, 'index'])->name('llm-credentials.index');
        Route::put('llm-credentials/{llmProvider}', [LlmCredentialController::class, 'update'])->name('llm-credentials.update');
        Route::delete('llm-credentials/{llmProvider}', [LlmCredentialController::class, 'destroy'])->name('llm-credentials.destroy');
    });

    Route::middleware('scope:avatar_configs:read')->group(function () {
        Route::get('avatar-configs', [AvatarConfigController::class, 'index'])->name('avatar-configs.index');
        Route::get('avatar-configs/{avatarConfig}', [AvatarConfigController::class, 'show'])->name('avatar-configs.show');
        Route::get('catalogue', [CatalogueController::class, 'index'])->name('catalogue.index');
        Route::get('catalogue/{provider}/{resource}', [CatalogueController::class, 'show'])->name('catalogue.show');
        Route::post('voice-preview', [VoicePreviewController::class, 'store'])->middleware('throttle:preview')->name('voice-preview.store');
    });

    Route::middleware('scope:avatar_configs:write')->group(function () {
        Route::post('avatar-configs', [AvatarConfigController::class, 'store'])->name('avatar-configs.store');
        Route::patch('avatar-configs/{avatarConfig}', [AvatarConfigController::class, 'update'])->name('avatar-configs.update');
        Route::post('avatar-configs/{avatarConfig}/sync', [AvatarConfigController::class, 'sync'])->name('avatar-configs.sync');
        Route::delete('avatar-configs/{avatarConfig}', [AvatarConfigController::class, 'destroy'])->name('avatar-configs.destroy');
    });

    Route::middleware('scope:webhook_endpoints:manage')->group(function () {
        Route::get('webhook-endpoints', [WebhookEndpointController::class, 'index'])->name('webhook-endpoints.index');
        Route::post('webhook-endpoints', [WebhookEndpointController::class, 'store'])->name('webhook-endpoints.store');
        Route::get('webhook-endpoints/{webhookEndpoint}', [WebhookEndpointController::class, 'show'])->name('webhook-endpoints.show');
        Route::patch('webhook-endpoints/{webhookEndpoint}', [WebhookEndpointController::class, 'update'])->name('webhook-endpoints.update');
        Route::delete('webhook-endpoints/{webhookEndpoint}', [WebhookEndpointController::class, 'destroy'])->name('webhook-endpoints.destroy');
        Route::get('webhook-endpoints/{webhookEndpoint}/deliveries', [WebhookDeliveryController::class, 'index'])->name('webhook-endpoints.deliveries.index');
    });

    Route::post('conversations', [ConversationController::class, 'store'])->middleware('scope:conversations:write')->name('conversations.store');
    Route::post('conversations/{conversation}/end', [ConversationController::class, 'end'])->middleware('scope:conversations:write')->name('conversations.end');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->middleware('scope:conversations:read')->name('conversations.show');

    Route::get('llm-models', [LlmModelController::class, 'index'])->middleware('scope:llm_models:read')->name('llm-models.index');
});

// Browser routes: a publishable key from an allowed origin plus the conversation's client secret, then a per-key budget.
// CORS is answered by the global ClientCors middleware for every route under `client/`.
Route::middleware([RejectKeyInQuery::class, 'auth.client', 'throttle:publishable'])->prefix('client')->group(function () {
    Route::post('conversations/{conversation}/connect', [ClientConversationController::class, 'connect'])->name('client.conversations.connect');
});
