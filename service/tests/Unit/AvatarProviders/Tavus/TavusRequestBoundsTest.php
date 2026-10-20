<?php

use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\Tavus\TavusProvider;
use App\Credentials\ProviderAuth;
use App\Models\AvatarConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Review rv11 R3-003: every call states how long it may take, so the guard's retries have a worst case that is known.
it('states a timeout and a connect timeout on every call the adapter makes', function (string $operation) {
    $options = [];
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options[] = $sent;

        return Http::response(['conversation_id' => 'c1', 'conversation_url' => 'https://tavus.daily.co/c1', 'status' => 'ended', 'events' => []], 200);
    });
    $adapter = new TavusProvider;
    $auth = new ProviderAuth('tvs_live_KEY', 'acc_1');
    $config = new AvatarConfig(['name' => 'T', 'provider' => 'tavus', 'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_1']]);

    match ($operation) {
        'start' => $adapter->start('conv_1', new ConversationSpec('Be kind.', 'Hello!', 'it', 600, new stdClass), $config, $auth, 'https://hra.example.test/cb'),
        'teardown' => $adapter->teardown('c1', null, $auth),
        'fetchSession' => $adapter->fetchSession('c1', $auth),
        'fetchTranscript' => rescue(fn () => $adapter->fetchTranscript('c1', $auth), null, false),
    };

    expect($options)->toHaveCount(1)
        ->and($options[0]['timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(15)
        ->and($options[0]['connect_timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(5);
})->with(['start', 'teardown', 'fetchSession', 'fetchTranscript']);
