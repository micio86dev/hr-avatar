<?php

use Illuminate\Support\Facades\File;
use Tests\Arch\VocabularyScanner;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/vocabulary-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->root.'/app', recursive: true);
    File::makeDirectory($this->root.'/tests/Arch', recursive: true);
});

afterEach(fn () => File::deleteDirectory($this->root));

function scanSource(string $root, string $code, string $path = 'app/Thing.php'): array
{
    File::ensureDirectoryExists(dirname("$root/$path"));
    File::put("$root/$path", $code);

    return (new VocabularyScanner)->scan([$root]);
}

it('rejects a host term and names the file, line and term', function () {
    $findings = scanSource($this->root, "<?php\n\n// Loads the candidate record.\n");

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['file'])->toEndWith('app/Thing.php')
        ->and($findings[0]['line'])->toBe(3)
        ->and($findings[0]['term'])->toBe('candidate');
});

it('passes a clean tree', function () {
    expect(scanSource($this->root, "<?php\n\n// Creates a conversation for an account.\n"))->toBe([]);
});

it('matches every banned term, singular and plural, in any case', function (string $text, string $term) {
    expect(scanSource($this->root, "<?php\n\$x = '$text';\n"))->toHaveCount(1)
        ->and(scanSource($this->root, "<?php\n\$x = '$text';\n")[0]['term'])->toBe($term);
})->with([
    ['beai', 'beai'],
    ['Participant', 'participant'],
    ['participants', 'participants'],
    ['CANDIDATES', 'candidates'],
    ['competency', 'competency'],
    ['Competencies', 'competencies'],
    ['BARS', 'bars'],
    ['interviews', 'interviews'],
    ['Project', 'project'],
    ['organizations', 'organizations'],
    ['Organisation', 'organisation'],
    ['organisations', 'organisations'],
    ['assessment', 'assessment'],
    ['Scores', 'scores'],
    ['tenant-of-beai', 'beai'],
]);

it('sees a term inside an identifier', function (string $identifier, string $term) {
    expect(scanSource($this->root, "<?php\n\$x = {$identifier};\n")[0]['term'])->toBe($term);
})->with([
    ['$candidate_id', 'candidate'],
    ['$competencyScore', 'competency'],
    ['Project::class', 'project'],
]);

it('does not match a term that is only part of a longer word', function (string $word) {
    expect(scanSource($this->root, "<?php\n\$x = '$word';\n"))->toBe([]);
})->with(['projection', 'scorecard', 'interviewer', 'barstool', 'subscore', 'candidacy']);

it('catches a reference to a BEAI namespace', function () {
    $findings = scanSource($this->root, "<?php\n\nuse Beai\\Shared\\Thing;\n");

    expect($findings)->toHaveCount(1)->and($findings[0]['term'])->toBe('beai');
});

it('exempts the architecture test files', function () {
    expect(scanSource($this->root, "<?php\n// candidate\n", 'tests/Arch/Anything.php'))->toBe([]);
});

it('scans every file under every root and skips a missing root', function () {
    File::put($this->root.'/app/Clean.php', "<?php\n");
    $findings = scanSource($this->root, "<?php\n// project\n", 'app/Nested/Deep.php');

    expect($findings)->toHaveCount(1);
    expect((new VocabularyScanner)->scan([$this->root.'/does-not-exist']))->toBe([]);
});

it('allows the provider wire key whose name contains a banned term, and nothing else on that line', function () {
    expect(scanSource($this->root, "<?php\n\$x = ['participant_left_timeout' => 60];\n"))->toBe([]);

    $findings = scanSource($this->root, "<?php\n\$x = ['participant_left_timeout' => \$participant];\n");

    expect($findings)->toHaveCount(1)->and($findings[0]['term'])->toBe('participant');
    expect(scanSource($this->root, "<?php\n\$x = 'participant_left';\n"))->toHaveCount(1);
});
