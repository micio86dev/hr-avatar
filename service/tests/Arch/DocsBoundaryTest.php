<?php

use Tests\Arch\VocabularyScanner;

/*
 * The documentation holds to the same boundary as the code: no vocabulary of a host product, every setting the
 * service reads named where it is deployed, and nothing in this repository that deploys.
 */
function repositoryRoot(): string
{
    return dirname(base_path());
}

/**
 * The documents that exist: the README files and everything under docs/, as path => name shown in a finding.
 *
 * @return array<string, string>
 */
function documents(): array
{
    $root = repositoryRoot();
    $documents = [];

    foreach (['README.md', 'service/README.md', 'sdk/README.md'] as $readme) {
        if (is_file("{$root}/{$readme}")) {
            $documents["{$root}/{$readme}"] = $readme;
        }
    }

    foreach (glob("{$root}/docs/*.md") ?: [] as $doc) {
        $documents[$doc] = 'docs/'.basename($doc);
    }

    return $documents;
}

/**
 * Runs the vocabulary scanner over the documents. The scanner reads directories, so the documents are copied
 * into one for the scan; a finding names the document, not the copy.
 *
 * @return list<string>
 */
function documentFindings(): array
{
    $staging = sys_get_temp_dir().'/docs-scan-'.bin2hex(random_bytes(6));
    mkdir($staging);
    $names = [];

    foreach (documents() as $path => $name) {
        $copy = str_replace('/', '__', $name);
        copy($path, "{$staging}/{$copy}");
        $names[$copy] = $name;
    }

    $findings = array_map(fn (array $f) => ($names[basename($f['file'])] ?? $f['file']).":{$f['line']} contains \"{$f['term']}\"", (new VocabularyScanner)->scan([$staging]));
    array_map('unlink', glob("{$staging}/*") ?: []);
    rmdir($staging);

    return $findings;
}

/** @return array<string, array{required: string, services: string, text: string}> the rows of the variable table, by name */
function variableRows(): array
{
    $text = (string) file_get_contents(repositoryRoot().'/docs/railway.md');
    $rows = [];

    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^\|\s*`([A-Z][A-Z0-9_]+)`\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|(.*)\|\s*$/', $line, $m) === 1) {
            $rows[$m[1]] = ['required' => strtolower($m[2]), 'services' => strtolower($m[3]), 'text' => trim($m[4])];
        }
    }

    return $rows;
}

describe('docs/railway.md', function () {
    it('exists and describes the topology: api, worker, scheduler, PostgreSQL and Redis, one config file per service', function () {
        $text = (string) file_get_contents(repositoryRoot().'/docs/railway.md');

        expect($text)->toContain('railway.json')->toContain('railway.worker.json')->toContain('railway.scheduler.json')
            ->toContain('PostgreSQL')->toContain('Redis')->toContain('LlmModelSeeder')->toContain('/v1/health');
        foreach (['api', 'worker', 'scheduler'] as $service) {
            expect(strtolower($text))->toContain($service);
        }
    });

    it('names, in a table, every setting a deployment needs, and marks which are required', function (string $name, string $required) {
        $rows = variableRows();

        expect($rows)->toHaveKey($name)->and($rows[$name]['required'])->toBe($required, "{$name} should be {$required}");
    })->with([
        ['APP_KEY', 'required'],
        ['DB_URL', 'required'],
        ['REDIS_URL', 'required'],
        ['HRA_JWT_SECRET', 'required'],
        ['HRA_PUBLIC_BASE_URL', 'required'],
        ['TAVUS_API_KEY', 'optional'],
        ['HEYGEN_API_KEY', 'optional'],
        ['CARTESIA_API_KEY', 'optional'],
        ['ELEVENLABS_API_KEY', 'optional'],
        ['HRA_RATE_LIMIT_SECRET', 'optional'],
        ['HRA_RATE_LIMIT_PUBLISHABLE', 'optional'],
        ['HRA_RATE_LIMIT_PREVIEW', 'optional'],
        ['HRA_RELEASE_DELAY_SECONDS', 'optional'],
        ['HRA_MAX_DURATION_CEILING', 'optional'],
        ['HRA_TRUSTED_PROXIES', 'optional'],
    ]);

    it('documents every HRA_ and provider setting the configuration reads, so a new one cannot go unmentioned', function () {
        $read = [];
        foreach (['hr-avatar.php', 'trustedproxy.php'] as $file) {
            preg_match_all("/env\('([A-Z0-9_]+)'/", (string) file_get_contents(base_path("config/{$file}")), $m);
            array_push($read, ...$m[1]);
        }

        expect($read)->not->toBeEmpty()->and(array_diff($read, array_keys(variableRows())))->toBe([]);
    });

    it('explains what the public base url is for, and that an unset one fails readiness and connect', function () {
        $row = variableRows()['HRA_PUBLIC_BASE_URL'];

        expect($row['text'])->toContain('callback')->toContain('/v1/health/ready');
    });

    it('says the provider keys are optional because an account may store its own', function () {
        expect(variableRows()['TAVUS_API_KEY']['text'])->toContain('account');
    });

    it('says the files are configuration as code that is never applied from this repository', function () {
        $text = strtolower((string) file_get_contents(repositoryRoot().'/docs/railway.md'));

        expect($text)->toContain('never applied')->toContain('config as code')->toContain('2026-12-01');
    });
});

it('is boundary-clean: no host-product vocabulary in any document', function () {
    $findings = documentFindings();

    expect(documents())->not->toBeEmpty()->and($findings)->toBe([], implode("\n", $findings));
});

it('finds host-product vocabulary in a document, and names the document', function () {
    $path = repositoryRoot().'/docs/zz-scan-probe.md';
    file_put_contents($path, "# Probe\n\nThe candidate record.\n");

    try {
        $findings = documentFindings();
    } finally {
        unlink($path);
    }

    expect($findings)->toBe(['docs/zz-scan-probe.md:3 contains "candidate"']);
});

it('is never run from this repository: no workflow, script or manifest calls the Railway CLI or API', function () {
    $scanned = array_merge(
        glob(repositoryRoot().'/.github/workflows/*') ?: [],
        [base_path('composer.json'), repositoryRoot().'/sdk/package.json', repositoryRoot().'/tools/spectral/package.json', base_path('Dockerfile'), base_path('docker-compose.yml')],
        glob(base_path('docker/*')) ?: [],
        glob(repositoryRoot().'/sdk/scripts/*') ?: [],
    );
    $calls = [];

    foreach ($scanned as $file) {
        if (is_file($file) && preg_match('/\brailway\s+(up|deploy|login|link|run|redeploy|variables|environment|service|add)\b|backboard\.railway|RAILWAY_(API_)?TOKEN|railwayapp\/cli|@railway\/cli/i', (string) file_get_contents($file), $m) === 1) {
            $calls[] = basename($file).': '.$m[0];
        }
    }

    expect($scanned)->not->toBeEmpty()->and($calls)->toBe([]);
});
