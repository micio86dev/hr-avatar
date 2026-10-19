<?php

use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\Tavus\TavusProvider;
use App\Credentials\ProviderAuth;
use App\Models\AvatarConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The fixtures are docs-verified against Tavus's create-conversation contract, not recordings of a
 * live call (no live key is ever needed to run the suite). The response fixture proves the parsing
 * reads the fields where Tavus puts them; the golden request proves the outbound body has not
 * CHANGED since a person last checked it, not that Tavus accepts it.
 */
function tavusFixture(string $name): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 3)."/Fixtures/Tavus/{$name}"), true, flags: JSON_THROW_ON_ERROR);
}

it('reads the conversation id and url from the top level of the create response', function () {
    $fixture = tavusFixture('conversation_create_response.json');
    Http::fake(['*' => Http::response($fixture, 200)]);

    $session = (new TavusProvider)->start(
        'conv_abc',
        new ConversationSpec('p', null, 'en', 600, new stdClass),
        new AvatarConfig(['name' => 'Tavus', 'provider' => 'tavus', 'config' => []]),
        new ProviderAuth('key-key-key', 'acc_1'),
        null,
    );

    expect($session->sessionRef)->toBe($fixture['conversation_id'])
        ->and($session->joinMaterial->conversationUrl)->toBe($fixture['conversation_url']);
});

it('sends the create body of the golden fixture', function () {
    Http::fake(['*' => Http::response(tavusFixture('conversation_create_response.json'), 200)]);

    (new TavusProvider)->start(
        'conv_abc',
        new ConversationSpec('TEST_PROMPT', 'TEST_OPENING', 'it', 600, new stdClass),
        new AvatarConfig(['name' => 'Tavus', 'provider' => 'tavus', 'config' => [
            'replica_id' => 'r_golden', 'persona_id' => 'p_golden', 'participant_left_timeout' => 60,
        ]]),
        new ProviderAuth('key-key-key', 'acc_1'),
        'https://hra.example.test/webhooks/providers/tavus/TOKEN',
    );

    $sent = Http::recorded()->first()[0];

    expect($sent)->toBeInstanceOf(Request::class)
        ->and($sent->data())->toEqual(tavusFixture('conversations_request_golden.json'));
});
