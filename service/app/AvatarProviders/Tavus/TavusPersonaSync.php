<?php

namespace App\AvatarProviders\Tavus;

use App\AvatarProviders\ProviderException;
use App\AvatarProviders\SyncBlocked;
use App\AvatarProviders\SyncResult;
use App\AvatarProviders\VoiceBinding;
use App\Credentials\LlmCredentialResolver;
use App\Credentials\ProviderAuth;
use App\Llm\LlmBinding;
use App\Llm\LlmBindingResolver;
use App\Models\AvatarConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Pushes an avatar config to its Tavus persona. Tavus keeps part of the configuration on the
 * persona, not on the conversation: the persona knobs, the external voice and the language-model
 * binding live there, and sent on a conversation they are ignored without an error.
 *
 * The update is one operation that replaces the whole `layers` node, so the layers are always built
 * complete from the config. A persona with nothing to say is not touched at all: an empty `layers`
 * would wipe what it holds. Known limit: removing the last persona knob therefore leaves the old
 * value on the persona until another knob is set.
 *
 * Tavus does not keep a previously sent `layers.llm.api_key` (omitting it is a 400, observed live
 * 2026-08-26), so the key is re-read and re-sent on every sync. A failure carries the status and
 * nothing of what Tavus wrote or what was sent (the body holds the language-model key).
 */
final class TavusPersonaSync
{
    private const BASE_URL = 'https://tavusapi.com/v2/pals';

    /** Bounded so a slow provider cannot hold a worker open. */
    private const TIMEOUT_SECONDS = 10;

    /** Persona key => dotted path inside `layers`. */
    private const PATHS = [
        'llm_temperature' => 'llm.extra_body.temperature',
        'llm_speculative_inference' => 'llm.speculative_inference',
        'turn_taking_patience' => 'conversational_flow.turn_taking_patience',
        'interruptibility' => 'conversational_flow.pal_interruptibility',
        'voice_isolation' => 'conversational_flow.voice_isolation',
        'idle_engagement' => 'conversational_flow.idle_engagement',
    ];

    public function __construct(private readonly LlmBindingResolver $bindings = new LlmBindingResolver(new LlmCredentialResolver)) {}

    /**
     * @throws ProviderException when the provider refuses the update or cannot be reached
     * @throws SyncBlocked when the persona id or the language-model key is missing
     */
    public function sync(AvatarConfig $config, ProviderAuth $auth): SyncResult
    {
        $personaId = $config->config['persona_id'] ?? null;

        if (! is_string($personaId) || $personaId === '') {
            throw SyncBlocked::incompleteConfig();
        }

        $layers = $this->layers($config);

        if ($layers === []) {
            return SyncResult::synced();
        }

        try {
            $response = Http::withHeaders(['x-api-key' => $auth->apiKey()])
                ->timeout(self::TIMEOUT_SECONDS)
                ->patch(self::BASE_URL.'/'.rawurlencode($personaId), [['op' => 'add', 'path' => '/layers', 'value' => $layers]]);
        } catch (ConnectionException) {
            throw ProviderException::noResponse();
        }

        // 304: the persona already holds exactly these values, a successful no-op.
        if (! $response->successful() && $response->status() !== 304) {
            throw ProviderException::fromStatus($response->status());
        }

        return SyncResult::synced();
    }

    /**
     * @return array<string, mixed>
     */
    private function layers(AvatarConfig $config): array
    {
        $layers = [];

        foreach (self::PATHS as $key => $path) {
            $value = $config->persona[$key] ?? null;

            if ($value !== null) {
                Arr::set($layers, $path, $value);
            }
        }

        $voice = VoiceBinding::fromArray($config->voice);

        if ($voice?->isExternal() === true) {
            $model = $config->config['tts_model_name'] ?? TavusFieldSpecs::DEFAULT_TTS_MODEL[$voice->engine] ?? null;
            $layers['tts'] = array_filter(
                ['tts_engine' => $voice->engine, 'external_voice_id' => $voice->voiceId, 'tts_model_name' => $model],
                fn (?string $value) => $value !== null,
            );
        }

        $binding = $this->binding($config);

        // Merged into the `llm` node the temperature already wrote to: a shallow merge would drop one side.
        return $binding === null ? $layers : array_replace_recursive($layers, ['llm' => [
            'model' => $binding->modelKey,
            'base_url' => $binding->baseUrl,
            'api_key' => $binding->apiKey(),
        ]]);
    }

    /**
     * @throws SyncBlocked when a model is bound and the account has no key for it
     */
    private function binding(AvatarConfig $config): ?LlmBinding
    {
        if ($config->llm_model_id === null) {
            return null;
        }

        return $this->bindings->resolve($config) ?? throw SyncBlocked::llmCredentialMissing();
    }
}
