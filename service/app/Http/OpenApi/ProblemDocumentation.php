<?php

namespace App\Http\OpenApi;

use App\Http\Problem;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Documents the one error body the service answers with (RFC 9457 Problem+JSON) and the answers every
 * endpoint can give, once each, so no operation repeats them and none forgets them:
 *
 *  - every error response, however it was declared, points at the `Problem` schema (the framework's own
 *    `{message}` and `{message, errors}` shapes are replaced, since this service never answers with them);
 *  - an operation that takes a credential documents 401 and 429 (the key's budget, or the address's budget
 *    of failed authentications), and one with a path parameter documents 404;
 *  - an answer an operation documents itself, for its own reasons, is kept as it is.
 */
final class ProblemDocumentation
{
    /** @var array<int, array{0: string, 1: string}> code => component name and description */
    private const COMMON = [
        401 => ['Unauthenticated', 'The credential is missing or wrong (`unauthenticated`), has expired (`key_expired`) or was revoked (`key_revoked`); on a browser call also an invalid or expired client secret (`client_secret_invalid`, `client_secret_expired`).'],
        404 => ['NotFound', 'There is no such resource in this account (`not_found`). A resource of another account is not found either.'],
        422 => ['ValidationFailed', 'The request is not valid (`validation_failed`); `errors` maps each field to its messages.'],
        429 => ['RateLimited', 'Too many requests (`rate_limited`): the budget of the key, or of the address for failed authentications, is spent. `Retry-After` says when to retry.'],
    ];

    private const ALSO_RATE_LIMITED = 'It is also `rate_limited` when the budget of the key, or of the address for failed authentications, is spent; `Retry-After` says when to retry.';

    /** The framework's own error responses, and the common answer that replaces each. */
    private const FRAMEWORK = ['ValidationException' => 422, 'ModelNotFoundException' => 404];

    public static function document(OpenApi $document): void
    {
        $components = $document->components;
        $problem = $components->addSchema('Problem', Schema::fromType(self::shape()));
        $common = [];

        foreach (self::COMMON as $code => [$name, $description]) {
            $components->responses[$name] = Response::make($code)->setDescription($description)->setContent(Problem::CONTENT_TYPE, $problem);
            $common[$code] = new Reference('responses', $name, $components);
        }

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                $responses = array_map(fn (Response|Reference $response) => self::normalised($response, $problem, $common), $operation->responses ?? []);
                $have = array_map(fn (Response|Reference $r) => $r instanceof Reference ? self::codeOf($r) : (int) $r->code, $responses);
                $secured = ($operation->security ?? []) !== [];

                foreach ([401 => $secured, 404 => str_contains($path->path, '{'), 429 => $secured] as $code => $needed) {
                    $reference = $common[$code] ?? null;

                    if ($needed && $reference !== null && ! in_array($code, $have, true)) {
                        $responses[] = $reference;
                    }
                }

                // A 429 of its own (the provider's concurrency limit) hides the budget 429 every key can meet: say both.
                foreach ($responses as $response) {
                    if ($secured && $response instanceof Response && (int) $response->code === 429 && ! str_contains($response->description, '`rate_limited`')) {
                        $response->description .= ' '.self::ALSO_RATE_LIMITED;
                    }
                }

                $operation->responses = $responses;
            }
        }

        foreach (array_keys($components->responses) as $name) {
            if (isset(self::FRAMEWORK[basename(str_replace('\\', '/', (string) $name))])) {
                $components->removeResponse((string) $name);
            }
        }
    }

    /**
     * @param  array<int, Reference>  $common
     */
    private static function normalised(Response|Reference $response, Reference $problem, array $common): Response|Reference
    {
        if ($response instanceof Reference) {
            return $common[self::FRAMEWORK[self::nameOf($response)] ?? 0] ?? $response;
        }

        if ((int) $response->code >= 400) {
            $response->content = [Problem::CONTENT_TYPE => $problem];
        }

        return $response;
    }

    /** The status a referenced response answers: the framework's own, or one of the common answers. */
    private static function codeOf(Reference $reference): ?int
    {
        $name = self::nameOf($reference);

        foreach (self::COMMON as $code => [$common]) {
            if ($common === $name) {
                return $code;
            }
        }

        return null;
    }

    private static function nameOf(Reference $reference): string
    {
        return basename(str_replace('\\', '/', $reference->shortName ?: (string) $reference->fullName));
    }

    private static function shape(): ObjectType
    {
        $string = fn (string $description) => (new StringType)->setDescription($description);

        $type = new ObjectType;
        $type->addProperty('type', $string('Always `about:blank`.'));
        $type->addProperty('title', $string('The HTTP status text.'));
        $type->addProperty('status', (new IntegerType)->setDescription('The HTTP status.'));
        $type->addProperty('detail', $string('A sentence about this occurrence, in English; never a secret or a provider\'s message.'));
        $type->addProperty('code', $string('The stable machine-readable error code; it is never localized.'));
        $type->addProperty('request_id', $string('The request id, also sent as `X-Request-Id`; quote it when asking about a request.'));
        $type->addProperty('errors', (new ObjectType)->additionalProperties((new ArrayType)->setItems(new StringType))->setDescription('Only on `validation_failed`: each invalid field and its messages.'));
        $type->setRequired(['type', 'title', 'status', 'detail', 'code', 'request_id']);

        return $type;
    }
}
