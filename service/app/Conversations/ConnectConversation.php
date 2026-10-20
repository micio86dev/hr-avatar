<?php

namespace App\Conversations;

use App\Accounts\CurrentAccount;
use App\AvatarProviders\AvatarProvider;
use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\FailureClass;
use App\AvatarProviders\Ingestion;
use App\AvatarProviders\JoinMaterial;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ProviderRegistry;
use App\AvatarProviders\ProviderSession;
use App\Credentials\ProviderAuth;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Jobs\ReleaseProviderSession;
use App\Llm\LlmBindingResolver;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Connects a conversation: the one moment the provider is asked for a session.
 *
 * In this order: the credentials are checked (a miss leaves the conversation `created`); the
 * conversation is claimed with a compare-and-set to `connecting`, which exactly one caller can win;
 * the callback token is minted for a provider that calls back; the adapter starts the session, outside
 * any transaction; the provider's references are kept and the browser's side of the session is returned.
 * Anything that fails after the claim (the callback target, the start, keeping the references) fails the
 * conversation (`provider_capacity` when the provider is at capacity, `provider_error` otherwise) and the failure is
 * re-raised for the response; a session that was started and could not be recorded is ended at once.
 *
 * Nothing here knows a provider by name: the registry hands out the adapter, which says whether it calls
 * back and whether it needs a credential.
 */
final class ConnectConversation
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ConversationProviderAuth $auths,
        private readonly LlmBindingResolver $bindings,
        private readonly ConversationLifecycle $lifecycle,
    ) {}

    /**
     * @throws ApiProblem `already_connected`, `invalid_state`, `provider_credential_missing`, `llm_credential_missing`
     *                    or `service_unavailable` (a provider that calls back and no public base url)
     * @throws Throwable whatever the adapter's start raised, after the conversation has been failed
     */
    public function connect(Conversation $conversation): JoinMaterial
    {
        return CurrentAccount::runAs($conversation->account_id, function () use ($conversation): JoinMaterial {
            $this->refuseUnlessCreated($conversation->status);

            $config = $conversation->avatarConfig;
            $auth = $this->credentials($conversation, $config);
            $adapter = $this->providers->get($conversation->provider);
            $callbacks = $adapter->ingestion() === Ingestion::Callback;

            // A provider that calls back needs an address to call: without one nothing is changed and nothing is sent.
            if ($callbacks && PublicBaseUrl::get() === null) {
                Log::error('Provider callbacks are not configured', ['setting' => 'HRA_PUBLIC_BASE_URL']);

                throw new ApiProblem(ErrorCode::ServiceUnavailable, "The service is not configured to receive this provider's callbacks.");
            }

            $this->claim($conversation);

            // From here a retry is refused (`already_connected`), so whatever fails must end the conversation.
            try {
                $callbackUrl = $callbacks ? $this->callbackTarget($conversation) : null;
                $session = $adapter->start($conversation->id, $this->spec($conversation), $config, $auth, $callbackUrl);
            } catch (Throwable $failure) {
                $this->fail($conversation, $failure);

                throw $failure;
            }

            try {
                return $this->keep($conversation, $session);
            } catch (ApiProblem $over) {
                // The conversation ended while its session was being started; its release is already queued.
                throw $over;
            } catch (Throwable $failure) {
                // The session exists and its references could not be kept, so nothing could release it later.
                $this->fail($conversation, $failure);
                $this->abandon($adapter, $session, $auth, $conversation);

                throw $failure;
            }
        });
    }

    /**
     * @throws ApiProblem
     */
    private function refuseUnlessCreated(ConversationStatus $status): void
    {
        if ($status !== ConversationStatus::Created) {
            throw $this->refusal($status);
        }
    }

    private function refusal(ConversationStatus $status): ApiProblem
    {
        return new ApiProblem($status->isTerminal() ? ErrorCode::InvalidState : ErrorCode::AlreadyConnected);
    }

    /**
     * The credential the session is started with, resolved before anything changes: a provider that
     * takes none always has one; for any other the provider's key, and the model's when the config binds one.
     *
     * @throws ApiProblem
     */
    private function credentials(Conversation $conversation, AvatarConfig $config): ProviderAuth
    {
        $auth = $this->auths->for($conversation) ?? throw new ApiProblem(ErrorCode::ProviderCredentialMissing);

        if ($this->providers->requiresCredential($conversation->provider) && $config->llm_model_id !== null && $this->bindings->resolve($config) === null) {
            throw new ApiProblem(ErrorCode::LlmCredentialMissing);
        }

        return $auth;
    }

    /**
     * The compare-and-set: `created -> connecting`. Whoever loses finds out what the winner left.
     *
     * @throws ApiProblem
     */
    private function claim(Conversation $conversation): void
    {
        try {
            $won = $this->lifecycle->transition($conversation, ConversationStatus::Connecting);
        } catch (IllegalTransition $lost) {
            throw $this->refusal($lost->from);
        }

        if (! $won) {
            throw new ApiProblem(ErrorCode::AlreadyConnected);
        }
    }

    /**
     * Mints the callback token, keeps its hash and returns the target the provider will post to. The
     * plain token exists only in the returned address: it is stored nowhere and logged nowhere.
     */
    private function callbackTarget(Conversation $conversation): string
    {
        $token = bin2hex(random_bytes(32));

        Conversation::query()->whereKey($conversation->id)->update(['callback_token_hash' => hash('sha256', $token)]);

        return PublicBaseUrl::get()."/v1/webhooks/providers/{$conversation->provider}/{$token}";
    }

    private function spec(Conversation $conversation): ConversationSpec
    {
        return new ConversationSpec($conversation->system_prompt, $conversation->opening_text, $conversation->language, $conversation->max_duration_seconds, $conversation->metadata);
    }

    /**
     * A conversation whose session could not be started is over. If something else already ended it, it stays as it is.
     */
    private function fail(Conversation $conversation, Throwable $failure): void
    {
        $capacity = $failure instanceof ProviderException && $failure->failureClass() === FailureClass::Throttle;

        try {
            $this->lifecycle->transition($conversation, ConversationStatus::Failed, $capacity ? EndedReason::ProviderCapacity : EndedReason::ProviderError);
        } catch (IllegalTransition) {
            // Ended in the meantime: the failure is still the caller's to report.
        }
    }

    /**
     * Ends a session whose references could not be stored, at once: it has no record to be released from later.
     * A failure to end it is logged by kind and does not replace the failure being reported.
     */
    private function abandon(AvatarProvider $adapter, ProviderSession $session, ProviderAuth $auth, Conversation $conversation): void
    {
        try {
            $adapter->teardown($session->sessionRef, $session->contextRef, $auth);
        } catch (Throwable $failure) {
            Log::error('A provider session could not be ended after a failed connect', ['conversation_id' => $conversation->id, 'exception' => $failure::class]);
        }
    }

    /**
     * Keeps the provider's references. A conversation that ended while its session was being started
     * found no session to release, so the release is queued here for the one that now exists.
     *
     * @throws ApiProblem `invalid_state` when the conversation is over
     */
    private function keep(Conversation $conversation, ProviderSession $session): JoinMaterial
    {
        Conversation::query()->whereKey($conversation->id)->update(['provider_session_ref' => $session->sessionRef, 'provider_context_ref' => $session->contextRef]);
        $conversation->reload();

        if ($conversation->status->isTerminal()) {
            ReleaseProviderSession::dispatch($conversation->account_id, $conversation->id)->delay(config()->integer('hr-avatar.release_delay_seconds'));

            throw new ApiProblem(ErrorCode::InvalidState);
        }

        return $session->joinMaterial;
    }
}
