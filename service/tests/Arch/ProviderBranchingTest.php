<?php

use Illuminate\Support\Facades\File;
use Tests\Arch\SourceScanner;

/**
 * The core never branches on a provider: a provider's name is written in its adapter (App\AvatarProviders) and in
 * the registry config, nowhere else. Anything else asks the adapter, which says what it needs.
 */
const PROVIDER_IDS = ['tavus', 'heygen', 'liveavatar'];
// Factories are test support that fills columns, not core code; they live beside the schema they fill.
const PROVIDER_HOMES = ['/app/AvatarProviders/', '/config/hr-avatar.php', '/database/factories/'];

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/provider-literals-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->root.'/app/AvatarProviders', recursive: true);
    File::makeDirectory($this->root.'/app/Billing', recursive: true);
});

afterEach(fn () => File::deleteDirectory($this->root));

it('rejects a branch on a provider outside the adapters, and names the class', function () {
    File::put($this->root.'/app/Billing/Branch.php', "<?php\n\nif (\$provider === 'tavus') {\n    return 1;\n}\n");

    $findings = (new SourceScanner)->providerLiterals([$this->root.'/app'], PROVIDER_IDS, PROVIDER_HOMES);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['class'])->toBe('App\\Billing\\Branch')
        ->and($findings[0]['line'])->toBe(3)
        ->and($findings[0]['literal'])->toBe('tavus');
});

it('rejects every spelling of a provider id in code, quoted either way and in any case', function (string $code) {
    File::put($this->root.'/app/Billing/Branch.php', "<?php\n\n{$code}\n");

    expect((new SourceScanner)->providerLiterals([$this->root.'/app'], PROVIDER_IDS, PROVIDER_HOMES))->toHaveCount(1);
})->with([
    'double quotes' => ['$x = "heygen";'],
    'upper case' => ["\$x = 'LiveAvatar';"],
    'match arm' => ["\$x = match (\$p) { 'tavus' => 1, default => 0 };"],
    'array key' => ["\$x = ['tavus' => 1];"],
]);

it('leaves a comment, a docblock and a longer string alone', function () {
    File::put($this->root.'/app/Billing/Prose.php', "<?php\n\n// Tavus and 'heygen' are named here.\n/** The tavus adapter. */\n\$x = 'tavusapi.com';\n\$y = \"a tavus b\";\n");

    expect((new SourceScanner)->providerLiterals([$this->root.'/app'], PROVIDER_IDS, PROVIDER_HOMES))->toBe([]);
});

it('allows an adapter to name its provider', function () {
    File::put($this->root.'/app/AvatarProviders/Adapter.php', "<?php\n\n\$id = 'tavus';\n");

    expect((new SourceScanner)->providerLiterals([$this->root.'/app'], PROVIDER_IDS, PROVIDER_HOMES))->toBe([]);
});

it('passes on the real tree: provider ids are written only in the adapters and the registry config', function () {
    $findings = (new SourceScanner)->providerLiterals([base_path('app'), base_path('config'), base_path('routes'), base_path('database')], PROVIDER_IDS, PROVIDER_HOMES);

    expect($findings)->toBe([], collect($findings)->map(fn (array $f) => "{$f['class']}:{$f['line']} names \"{$f['literal']}\"")->implode("\n"));
});
