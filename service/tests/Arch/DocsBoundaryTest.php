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

/** The text of a document, as the repository root's relative path. */
function documentText(string $relative): string
{
    $path = repositoryRoot().'/'.$relative;

    expect(file_exists($path))->toBeTrue("{$relative} does not exist");

    return (string) file_get_contents($path);
}

/** True when `$haystack` holds every needle, in order. */
function inOrder(string $haystack, array $needles): bool
{
    $at = 0;

    foreach ($needles as $needle) {
        $found = strpos($haystack, $needle, $at);

        if ($found === false) {
            return false;
        }

        $at = $found + strlen($needle);
    }

    return true;
}

describe('README.md and docs/architecture.md', function () {
    it('exist, and state the retention open item: nothing is purged, and the owner has not decided', function (string $relative) {
        $text = strtolower(documentText($relative));

        expect($text)->toContain('retention')->toContain('no purge')
            ->and($text)->toContain('transcripts')->toContain('events')->toContain('receipts')->toContain('delivery payloads')
            ->and($text)->toContain('owner decision');
    })->with(['README.md', 'docs/architecture.md']);

    it('tell how to run the stack, test it and what gates it, with the local ports', function () {
        $readme = documentText('README.md');

        expect($readme)->toContain('55432')->toContain('56379')->toContain('8100')
            ->toContain('php artisan test')->toContain('--coverage --min=90')->toContain('phpunit.high-integrity.xml')
            ->toContain('bun run test:coverage')->toContain('bun run build')->toContain('bun run size')
            ->toContain('composer run analyse')->toContain('vendor/bin/pint')->toContain('Git Flow');
    });

    it('point at the owner ratification items, and list the assumptions that stay assumptions', function () {
        $readme = documentText('README.md');

        expect($readme)->toContain('Owner ratification items')->toContain('openspec/changes/hr-avatar-engine/design.md')
            ->and($readme)->toContain('Known assumptions')->toContain('LiveAvatar')->toContain('session token')->toContain('editable');
    });

    it('describe the provider contract, the lazy start at connect, the state machine, the event flow and the key model', function () {
        $text = documentText('docs/architecture.md');

        foreach (['AvatarProvider', 'start', 'teardown', 'fetchTranscript', 'callbackTranscript', 'fetchSession', 'parseCallback', 'ingestion', 'created', 'connecting', 'live', 'ended', 'failed', 'client_secret', 'hra_sk_', 'hra_pk_', 'account_id'] as $term) {
            expect($text)->toContain($term);
        }
        expect($text)->toContain('```mermaid')->and(strtolower($text))->toContain('provider-agnostic');
    });

    it('place the service README beside the service and the SDK README beside the SDK', function () {
        expect(documentText('service/README.md'))->toContain('php artisan test')
            ->and(documentText('sdk/README.md'))->toContain('@hr-avatar/sdk')->toContain('bun');
    });
});

describe('docs/api-usage.md', function () {
    it('walks the whole flow with curl, in order: key, credential, config, conversation, connect, events, end, transcript', function () {
        $text = documentText('docs/api-usage.md');

        expect(inOrder($text, [
            'hra:bootstrap-account', '/v1/provider-credentials/', '/v1/avatar-configs', '/v1/conversations', '/connect', '/events', '/end', '/transcript',
        ]))->toBeTrue()->and(substr_count($text, 'curl '))->toBeGreaterThanOrEqual(8);
    });

    it('covers keys, scopes, the browser connect through the SDK, webhooks and errors', function () {
        $text = documentText('docs/api-usage.md');

        foreach (['hra_sk_', 'hra_pk_', '/v1/api-keys', 'allowed_origins', 'scopes', 'client_secret', '@hr-avatar/sdk', 'HrAvatar.connect', '/v1/webhook-endpoints', 'Problem', 'request_id', 'Retry-After', 'external_ref'] as $term) {
            expect($text)->toContain($term);
        }
    });

    it('explains how to verify a webhook: the signature, the timestamp window, and dedupe on the event id', function () {
        $text = documentText('docs/api-usage.md');

        foreach (['X-HRA-Signature', 'X-HRA-Timestamp', 'X-HRA-Event-Id', 'X-HRA-Event-Type', '300', 'hash_equals', 'v1='] as $term) {
            expect($text)->toContain($term);
        }
        expect(strtolower($text))->toContain('constant-time')->toContain('dedupe');
    });

    it('calls only operations that exist in the OpenAPI document, with the right method', function () {
        $document = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $operations = [];
        foreach ($document['paths'] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $operations[] = [strtoupper($method), '#^/v1'.preg_replace('#\{[^}]+\}#', '[^/]+', $path).'$#'];
            }
        }
        $text = documentText('docs/api-usage.md');
        preg_match_all('#curl[^\n]*?-X (GET|POST|PUT|PATCH|DELETE)[^\n]*?"\$HRA_URL(/v1[^"?]*)#', $text, $calls, PREG_SET_ORDER);

        expect($calls)->not->toBeEmpty();
        foreach ($calls as [, $method, $path]) {
            $path = preg_replace('#\$\{?[A-Z_]+\}?#', 'x', $path);
            $matches = array_filter($operations, fn (array $op) => $op[0] === $method && preg_match($op[1], $path) === 1);

            expect($matches)->not->toBeEmpty("docs/api-usage.md calls {$method} {$path}, which the API does not have");
        }
    });

    it('holds no real key: every key shown is a placeholder', function () {
        $text = documentText('docs/api-usage.md');

        expect(preg_match('/hra_(sk|pk)_(live|test)_[0-9a-f]{20,}/', $text))->toBe(0)
            ->and(preg_match('/hra_whsec_[0-9a-f]{20,}/', $text))->toBe(0);
    });
});

describe('docs/sdk-usage.md', function () {
    it('documents the connect options, the events, the controls and the error codes', function () {
        $text = documentText('docs/sdk-usage.md');

        foreach (['HrAvatar.connect', 'baseUrl', 'publishableKey', 'clientSecret', 'mount', 'audioDeviceId', 'completionPhrases', 'forwardTranscript', 'transports', 'loaders'] as $option) {
            expect($text)->toContain($option);
        }
        foreach (['state', 'transcript', 'error', 'ended', 'connecting', 'live', 'completion_phrase'] as $event) {
            expect($text)->toContain($event);
        }
        foreach (['end()', 'toggleMic()', 'setMicMuted', 'say(', 'interrupt()', 'sendContext(', 'whenAvatarSilent'] as $control) {
            expect($text)->toContain($control);
        }
        foreach (['invalid_client_secret', 'conversation_ended', 'unsupported_operation', 'unsupported_transport', 'provider_library_missing', 'network_error', 'HrAvatarError', 'recoverable', 'request_id'] as $code) {
            expect($text)->toContain($code);
        }
    });

    it('documents the mock export and the script-tag recipe with CDN-backed loaders', function () {
        $text = documentText('docs/sdk-usage.md');

        foreach (['@hr-avatar/sdk/mock', 'createMockTransport', 'window.HrAvatar', 'hr-avatar.global.js', 'loaders', 'daily', 'liveavatar', 'provider_library_missing', '20 KB'] as $term) {
            expect($text)->toContain($term);
        }
    });

    it('names the peer libraries and their pinned versions as the SDK declares them', function () {
        $package = json_decode((string) file_get_contents(repositoryRoot().'/sdk/package.json'), true, flags: JSON_THROW_ON_ERROR);
        $text = documentText('docs/sdk-usage.md');

        foreach ($package['peerDependencies'] as $name => $version) {
            expect($text)->toContain($name)->toContain($version);
        }
    });
});

/**
 * Relative links that lead nowhere: a missing file, or a heading the file does not have (GitHub's anchor rules).
 *
 * @param  array<string, string>  $documents  path => name shown
 * @return list<string>
 */
function brokenLinks(array $documents): array
{
    $broken = [];

    foreach ($documents as $path => $name) {
        preg_match_all('/\]\(([^)\s]+)\)/', (string) file_get_contents($path), $links);

        foreach ($links[1] as $link) {
            if (preg_match('#^(https?:|mailto:)#', $link) === 1) {
                continue;
            }

            [$file, $anchor] = array_pad(explode('#', $link, 2), 2, null);
            $target = $file === '' ? $path : realpath(dirname($path).'/'.$file);

            if ($target === false || ! file_exists($target)) {
                $broken[] = "{$name}: {$link} (no such file)";

                continue;
            }

            if ($anchor !== null && str_ends_with($target, '.md')) {
                preg_match_all('/^#{1,6}\s+(.+)$/m', (string) file_get_contents($target), $headings);
                $slugs = array_map(fn (string $h) => str_replace(' ', '-', trim((string) preg_replace('/[^a-z0-9 \-]/', '', strtolower(trim($h))))), $headings[1]);

                if (! in_array($anchor, $slugs, true)) {
                    $broken[] = "{$name}: {$link} (no such heading)";
                }
            }
        }
    }

    return $broken;
}

it('links only to files and headings that exist', function () {
    expect(documents())->not->toBeEmpty()->and(brokenLinks(documents()))->toBe([], implode("\n", brokenLinks(documents())));
});

it('finds a link to a missing file or heading, and passes a good one', function () {
    $path = repositoryRoot().'/docs/zz-link-probe.md';
    file_put_contents($path, "# Probe\n\n[gone](./nope.md) and [bad anchor](./railway.md#no-such-heading) and [ok](./railway.md#topology) and [self](#probe) and [web](https://example.com)\n");

    try {
        $broken = brokenLinks([$path => 'docs/zz-link-probe.md']);
    } finally {
        unlink($path);
    }

    expect($broken)->toBe(['docs/zz-link-probe.md: ./nope.md (no such file)', 'docs/zz-link-probe.md: ./railway.md#no-such-heading (no such heading)']);
});
