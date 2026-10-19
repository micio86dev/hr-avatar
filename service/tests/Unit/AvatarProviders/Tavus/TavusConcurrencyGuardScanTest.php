<?php

/**
 * The guard retries; it never makes room. The way to free a Tavus concurrency slot is to list the
 * account's active conversations and end them, which on a shared provider account would end other
 * accounts' live conversations. This file scan keeps that out of the adapter for good.
 */
function tavusExecutableCode(string $file): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/**
 * @return array<string, string> executable code by file name
 */
function tavusAdapterCode(): array
{
    $code = [];

    foreach (glob(dirname(__DIR__, 4).'/app/AvatarProviders/Tavus/*.php') ?: [] as $file) {
        $code[basename($file)] = tavusExecutableCode($file);
    }

    return $code;
}

it('scans the adapter files it claims to', function () {
    expect(array_keys(tavusAdapterCode()))->toContain('TavusConcurrencyGuard.php', 'TavusProvider.php');
});

it('makes no provider call from the guard itself', function () {
    $code = tavusAdapterCode()['TavusConcurrencyGuard.php'];

    expect($code)->not->toContain('Http::')->not->toMatch('/->(get|post|put|patch|delete|head)\(/');
});

it('never reaches for a conversation list or an active status', function (string $file, string $code) {
    expect($code)->not->toContain('status=active')
        ->not->toContain('endActiveTavusConversations')
        ->not->toMatch('/[\'"]\/conversations[?\'"]\s*\./')
        ->not->toMatch('/\/conversations\?/');
})->with(fn () => array_map(fn (string $code, string $file) => [$file, $code], tavusAdapterCode(), array_keys(tavusAdapterCode())));

it('only creates a conversation, addresses the one it was given, updates the one persona it syncs, or lists the inventory', function (string $file, string $code) {
    preg_match_all('/->(get|post|put|patch|delete|head)\(([^;]*)/', $code, $calls, PREG_SET_ORDER);

    $strangers = array_filter($calls, function (array $call): bool {
        [, $verb, $arguments] = $call;
        $isCreate = $verb === 'post' && preg_match('/^[^,)]*[\'"]\/conversations[\'"]\s*[,)]/', $arguments) === 1;

        // The persona update of TavusPersonaSync: one PATCH on the persona id of the config being synced.
        $isPersonaUpdate = $verb === 'patch' && str_contains($arguments, 'rawurlencode($personaId)');

        // The inventory listing of TavusCatalogue: a GET of a face or persona list, never of conversations.
        $isInventoryList = $verb === 'get' && str_contains($arguments, 'self::BASE_URL.$path');

        return ! $isCreate && ! $isPersonaUpdate && ! $isInventoryList && ! str_contains($arguments, '$sessionRef');
    });

    expect(array_column($strangers, 0))->toBe([], $file);
})->with(fn () => array_map(fn (string $code, string $file) => [$file, $code], tavusAdapterCode(), array_keys(tavusAdapterCode())));

it('updates a persona from the persona sync alone', function () {
    $patchers = array_keys(array_filter(tavusAdapterCode(), fn (string $code) => preg_match('/->patch\(/', $code) === 1));

    expect($patchers)->toBe(['TavusPersonaSync.php']);
});

it('lists the inventory from the catalogue alone, and the catalogue never mentions a conversation', function () {
    $code = tavusAdapterCode();
    $listers = array_keys(array_filter($code, fn (string $source) => str_contains($source, 'self::BASE_URL.$path')));

    expect($listers)->toBe(['TavusCatalogue.php'])
        ->and(strtolower($code['TavusCatalogue.php']))->not->toContain('conversation');
});
