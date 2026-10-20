<?php

use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\Tavus\TavusProvider;
use App\Credentials\ProviderAuth;
use App\Models\AvatarConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const TAVUS_LEAK_KEY = 'tvs_live_SUPER_SECRET_KEY_99999';

beforeEach(function () {
    Sleep::fake();
    $this->logs = captureLogs();
});

function tavusLeakStart(): void
{
    (new TavusProvider)->start(
        'conv_abc',
        new ConversationSpec('Be kind.', 'Hello!', 'en', 600, new stdClass),
        new AvatarConfig(['name' => 'Tavus', 'provider' => 'tavus', 'config' => ['replica_id' => 'r_1']]),
        new ProviderAuth(TAVUS_LEAK_KEY, 'acc_1'),
        null,
    );
}

// The provider text is built here, not passed in as a dataset: a trace lists a test's arguments.
it('keeps the key and the provider body out of the error, its trace and the log when Tavus echoes the key', function (int $status, bool $atCapacity) {
    $message = ($atCapacity ? 'Maximum concurrent conversations reached, ' : '').'echoed '.TAVUS_LEAK_KEY;
    Http::fake(['*' => Http::response(['error' => $message], $status)]);

    try {
        tavusLeakStart();
        $this->fail('Expected a ProviderException');
    } catch (ProviderException $e) {
        report($e);
        // The trace also lists this test's own arguments, so it is only held to the key itself.
        expect($e->getTraceAsString())->not->toContain(TAVUS_LEAK_KEY)
            ->and($e->getMessage().implode("\n", $this->logs->getArrayCopy()))
            ->not->toContain('SUPER_SECRET')
            ->not->toContain('echoed')
            ->and($e->getPrevious())->toBeNull()
            ->and($e->context())->toBe(['status_class' => intdiv($status, 100).'xx']);
    }
})->with([
    'unauthorized' => [401, false],
    'server error' => [500, false],
    'at capacity' => [400, true],
]);

it('keeps the key out of the error when the transport fails with a message that contains it', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 7 while sending '.TAVUS_LEAK_KEY)]);

    try {
        tavusLeakStart();
        $this->fail('Expected a ProviderException');
    } catch (ProviderException $e) {
        report($e);

        expect($e->getMessage().implode("\n", $this->logs->getArrayCopy()))->not->toContain(TAVUS_LEAK_KEY);
    }
});

it('keeps the key out of the join material and out of the log on success', function () {
    Http::fake(['*' => Http::response(['conversation_id' => 'c1', 'conversation_url' => 'https://tavus.daily.co/c1'], 200)]);

    $session = (new TavusProvider)->start(
        'conv_abc',
        new ConversationSpec('Be kind.', null, 'en', 600, new stdClass),
        new AvatarConfig(['name' => 'Tavus', 'provider' => 'tavus', 'config' => []]),
        new ProviderAuth(TAVUS_LEAK_KEY, 'acc_1'),
        null,
    );

    expect(json_encode($session->joinMaterial->toArray()).print_r($session->joinMaterial, true).$session->sessionRef.implode($this->logs->getArrayCopy()))
        ->not->toContain(TAVUS_LEAK_KEY);
});
