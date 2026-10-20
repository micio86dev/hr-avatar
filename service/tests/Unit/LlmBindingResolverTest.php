<?php

use App\Credentials\LlmCredentialResolver;
use App\Llm\LlmBinding;
use App\Llm\LlmBindingResolver;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->model = LlmModel::factory()->create(['provider' => 'gemini', 'model' => 'gemini-3.1-flash-lite-preview', 'base_url' => 'https://llm.example.test/v1/']);
    $this->resolver = new LlmBindingResolver(new LlmCredentialResolver);
});

function configBoundTo(?LlmModel $model, Account $account): AvatarConfig
{
    return inAccount($account, fn () => AvatarConfig::factory()->create(['llm_model_id' => $model?->id]));
}

function storeGeminiKey(Account $account, string $key = 'sk-real-key-123456'): void
{
    inAccount($account, fn () => LlmCredential::create(['llm_provider' => 'gemini', 'api_key' => $key]));
}

it('resolves null for a config with no LLM bound', function () {
    expect($this->resolver->resolve(configBoundTo(null, $this->account)))->toBeNull();
});

it('resolves the model string, base URL and the account\'s key for a bound config', function () {
    storeGeminiKey($this->account);

    $binding = $this->resolver->resolve(configBoundTo($this->model, $this->account));

    expect($binding)->toBeInstanceOf(LlmBinding::class)
        ->and($binding->modelKey)->toBe('gemini-3.1-flash-lite-preview')
        ->and($binding->baseUrl)->toBe('https://llm.example.test/v1/')
        ->and($binding->apiKey())->toBe('sk-real-key-123456');
});

it('carries nothing provider-specific beyond the three members', function () {
    expect(array_keys(get_class_vars(LlmBinding::class)))->toBe(['modelKey', 'baseUrl']);
});

it('resolves null, never throws, when the account has no key for the model\'s provider', function () {
    inAccount($this->account, fn () => LlmCredential::create(['llm_provider' => 'other-vendor', 'api_key' => 'other-key-123456']));

    expect(fn () => $this->resolver->resolve(configBoundTo($this->model, $this->account)))->not->toThrow(Throwable::class)
        ->and($this->resolver->resolve(configBoundTo($this->model, $this->account)))->toBeNull();
});

it('never uses another account\'s key', function () {
    storeGeminiKey(Account::factory()->create());

    expect($this->resolver->resolve(configBoundTo($this->model, $this->account)))->toBeNull();
});

it('resolves null, never throws, for a model that no longer resolves', function () {
    $config = new AvatarConfig;
    $config->forceFill(['account_id' => $this->account->id, 'llm_model_id' => 'no-such-model']);

    expect(fn () => $this->resolver->resolve($config))->not->toThrow(Throwable::class)
        ->and($this->resolver->resolve($config))->toBeNull();
});

it('resolves null when the stored key can no longer be decrypted, because the remedy is to enter it again', function () {
    storeGeminiKey($this->account);
    // Written under a different APP_KEY.
    DB::table('llm_credentials')->where('account_id', $this->account->id)->update(['api_key' => 'not-a-valid-ciphertext']);

    expect($this->resolver->resolve(configBoundTo($this->model, $this->account)))->toBeNull();
});

it('still resolves for a model disabled after the config was bound to it', function () {
    storeGeminiKey($this->account);
    $config = configBoundTo($this->model, $this->account);
    $this->model->update(['enabled' => false]);

    expect($this->resolver->resolve($config)?->modelKey)->toBe('gemini-3.1-flash-lite-preview');
});

describe('LlmBinding', function () {
    it('redacts the key when dumped', function () {
        $binding = new LlmBinding('model-x', 'https://llm.example.test/', 'sk-real-key-123456');

        ob_start();
        var_dump($binding);
        print_r($binding);
        $dumped = ob_get_clean();

        expect($dumped)->not->toContain('sk-real-key-123456')->toContain('[redacted]')->toContain('model-x');
    });

    it('keeps the key out of JSON and of a property listing', function () {
        $binding = new LlmBinding('model-x', 'https://llm.example.test/', 'sk-real-key-123456');

        expect(json_encode($binding))->not->toContain('sk-real-key-123456')->toContain('model-x')
            ->and(get_object_vars($binding))->not->toHaveKey('apiKey')
            ->and(json_encode(get_object_vars($binding)))->not->toContain('sk-real-key-123456');
    });

    it('refuses to be serialized, so a queued job cannot carry the key', function () {
        $binding = new LlmBinding('model-x', 'https://llm.example.test/', 'sk-real-key-123456');

        expect(fn () => serialize($binding))->toThrow(LogicException::class, 'cannot be serialized');
    });
});
