<?php

use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\Tavus\TavusPayload;
use App\Models\AvatarConfig;

function tavusSpec(string $prompt = 'Be kind.', ?string $opening = 'Hello!', string $language = 'en', int $maxDuration = 600): ConversationSpec
{
    return new ConversationSpec($prompt, $opening, $language, $maxDuration, new stdClass);
}

/**
 * @param  array<string, mixed>  $config
 */
function tavusConfig(array $config = ['replica_id' => 'r_1', 'persona_id' => 'p_1']): AvatarConfig
{
    return new AvatarConfig(['name' => 'Tavus', 'provider' => 'tavus', 'config' => $config]);
}

it('maps the spec onto the conversation body', function () {
    $body = TavusPayload::forConversation(tavusSpec('Be kind.', 'Hello!', 'it', 600), tavusConfig(), 'https://hra.example.test/cb');

    expect($body)->toBe([
        'conversational_context' => 'Be kind.',
        'custom_greeting' => 'Hello!',
        'callback_url' => 'https://hra.example.test/cb',
        'replica_id' => 'r_1',
        'persona_id' => 'p_1',
        'properties' => ['language' => 'italian', 'max_call_duration' => 600],
    ]);
});

it('omits the greeting when there is no opening text', function (?string $opening) {
    expect(TavusPayload::forConversation(tavusSpec(opening: $opening), tavusConfig(), null))->not->toHaveKey('custom_greeting');
})->with(['null' => [null], 'empty' => ['']]);

it('omits the callback url when none is given', function () {
    expect(TavusPayload::forConversation(tavusSpec(), tavusConfig(), null))->not->toHaveKey('callback_url');
});

it('sends the avatar identifiers the config holds and nothing it does not', function (array $config, array $expected) {
    $body = TavusPayload::forConversation(tavusSpec(), tavusConfig($config), null);

    expect(array_intersect_key($body, ['replica_id' => 1, 'persona_id' => 1]))->toBe($expected);
})->with([
    'both' => [['replica_id' => 'r_1', 'persona_id' => 'p_1'], ['replica_id' => 'r_1', 'persona_id' => 'p_1']],
    'persona only' => [['persona_id' => 'p_1'], ['persona_id' => 'p_1']],
    'replica only' => [['replica_id' => 'r_1'], ['replica_id' => 'r_1']],
    'empty strings' => [['replica_id' => '', 'persona_id' => ''], []],
    'wrong types' => [['replica_id' => 7, 'persona_id' => ['x']], []],
    'neither' => [[], []],
]);

it('adds the left-timeout only when the config sets a whole number', function (mixed $value, bool $sent) {
    $properties = TavusPayload::forConversation(tavusSpec(), tavusConfig(['participant_left_timeout' => $value]), null)['properties'];

    expect(array_key_exists('participant_left_timeout', $properties))->toBe($sent);
    if ($sent) {
        expect($properties['participant_left_timeout'])->toBe($value);
    }
})->with([
    'sixty' => [60, true],
    'zero' => [0, true],
    'null' => [null, false],
    'string' => ['60', false],
    'float' => [1.5, false],
]);

it('passes the language through the Tavus vocabulary and the duration as the call maximum', function () {
    $properties = TavusPayload::forConversation(tavusSpec(language: 'fr', maxDuration: 90), tavusConfig(), null)['properties'];

    expect($properties)->toBe(['language' => 'fr', 'max_call_duration' => 90]);
});
