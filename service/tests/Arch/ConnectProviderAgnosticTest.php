<?php

/**
 * Connecting must work for any provider the registry knows: the connect path resolves an adapter through
 * the registry and asks it what it needs, and never branches on a provider's name.
 */
it('names no provider id in the connect path', function (string $file) {
    $source = file_get_contents(base_path($file));
    $ids = [...array_keys(config()->array('hr-avatar.providers')), 'liveavatar', 'cartesia', 'elevenlabs'];

    $named = array_values(array_filter($ids, fn (string $id) => preg_match('/[\'"]'.preg_quote($id, '/').'[\'"]/i', (string) $source) === 1));

    expect($source)->not->toBeFalse()->and($named)->toBe([]);
})->with([
    'app/Conversations/ConnectConversation.php',
    'app/Http/Controllers/V1/Client/ClientConversationController.php',
]);
