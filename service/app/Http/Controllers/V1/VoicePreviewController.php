<?php

namespace App\Http\Controllers\V1;

use App\Accounts\CurrentAccount;
use App\AvatarProviders\ProviderException;
use App\Credentials\CredentialResolver;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Http\Problem;
use App\Http\Requests\VoicePreviewRequest;
use App\Voices\VoicePreview;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Support\Facades\Log;

/**
 * Hear a voice before binding it to an avatar config. Requires the `avatar_configs:read` scope.
 */
#[Group('Catalogue')]
class VoicePreviewController
{
    public function __construct(private readonly VoicePreview $preview, private readonly CredentialResolver $credentials) {}

    /**
     * Preview a voice
     *
     * Returns a short audio sample of one voice as base64. The sentence is the platform's own for the
     * `language`; the request cannot supply text (any member beyond the four is a 422). A voice vendor
     * synthesizes that sentence; LiveAvatar returns its own generic clip. A provider that has no voices
     * of its own is previewed through the external vendor named in `tts_engine`; without one there is
     * nothing to preview (422). The vendor is called with the account's credential, else the platform's,
     * and a repeat is served from a cache without a vendor call. Every preview request counts against
     * a budget of its own per key (10 a minute by default, on top of the key's general budget), because
     * each synthesis is a paid vendor call; beyond it the answer is 429 with `Retry-After`.
     *
     * @return array{content_type: string, audio_base64: string}
     */
    #[ResponseDoc(404, description: 'The vendor does not know the voice.', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    #[ResponseDoc(422, description: 'The body is not valid, the voice has no vendor to preview it, or no credential is available for the vendor (`provider_credential_missing`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    #[ResponseDoc(502, description: 'The vendor failed or could not be reached (`provider_error`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    public function store(VoicePreviewRequest $request): array
    {
        $vendor = $this->preview->vendorFor($request->string('provider')->toString(), $request->engine())
            ?? throw new ApiProblem(ErrorCode::ValidationFailed, errors: ['tts_engine' => ['This voice can be previewed only through an external voice engine.']]);

        $auth = $this->credentials->for(app(CurrentAccount::class)->require(), $vendor->id())
            ?? throw new ApiProblem(ErrorCode::ProviderCredentialMissing);

        try {
            $sample = $this->preview->preview($vendor, $request->string('voice_id')->toString(), $request->string('language')->toString(), $auth);
        } catch (ProviderException $failure) {
            Log::warning('Voice preview request failed', $failure->context());

            // A voice the vendor does not know is the caller's mistake; any other failure is the vendor's.
            throw new ApiProblem($failure->status() === 404 ? ErrorCode::NotFound : ErrorCode::ProviderError);
        }

        return ['content_type' => $sample['content_type'], 'audio_base64' => base64_encode($sample['audio'])];
    }
}
