<?php

use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * The committed openapi.json is the published contract. Regenerate it with
 * `composer run openapi:export` whenever a route, request or response changes.
 */
it('matches the committed openapi.json', function () {
    $path = base_path('openapi.json');

    expect(file_exists($path))->toBeTrue('service/openapi.json is not committed');

    $committed = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $generated = json_decode(json_encode(app(Generator::class)(), JSON_THROW_ON_ERROR), true);

    expect($generated)->toEqual($committed);
});

it('is generated for the product title and a relative server', function () {
    $committed = json_decode(file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($committed['info']['title'])->toBe('HR-Avatar')
        ->and($committed['servers'])->toBe([['url' => '/v1']])
        ->and($committed['openapi'])->toStartWith('3.1');
});

/*
 * `git diff` never reports a file git does not track, so a deleted-and-regenerated or never-committed
 * openapi.json would pass a diff-only drift check. The file must be tracked.
 */

/** true when git tracks the file, false when it does not, null when `$directory` is no git checkout. */
function gitTracks(string $directory, string $file): ?bool
{
    $inside = Process::path($directory)->run(['git', 'rev-parse', '--is-inside-work-tree']);

    if (! $inside->successful()) {
        return null;
    }

    return Process::path($directory)->run(['git', 'ls-files', '--error-unmatch', '--', $file])->successful();
}

it('is tracked by git', function () {
    $tracked = gitTracks(base_path(), 'openapi.json');

    expect($tracked)->toBeTrue('service/openapi.json is not tracked by git: commit it (git add service/openapi.json)');
})->skip(fn () => gitTracks(base_path(), 'openapi.json') === null, 'not a git checkout');

it('tells a tracked file from an untracked one', function () {
    $repo = sys_get_temp_dir().'/tracked-'.bin2hex(random_bytes(6));
    File::makeDirectory($repo);
    Process::path($repo)->run(['git', 'init', '--quiet'])->throw();
    File::put($repo.'/openapi.json', '{}');

    $untracked = gitTracks($repo, 'openapi.json');
    Process::path($repo)->run(['git', 'add', 'openapi.json'])->throw();
    $tracked = gitTracks($repo, 'openapi.json');
    $elsewhere = gitTracks(sys_get_temp_dir(), 'openapi.json');
    File::deleteDirectory($repo);

    expect($untracked)->toBeFalse()->and($tracked)->toBeTrue()->and($elsewhere)->toBeNull();
});
