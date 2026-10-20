<?php

/**
 * Ruling 52 (and 59 for the relay): the sweeper, the poller and the relay read per account, which is accepted at a modest number of accounts.
 * The ceiling and the way past it are written where the loop is, so the next reader finds them there.
 */
it('names the ceiling of walking every account where the loop is', function (string $file) {
    $source = (string) file_get_contents(base_path($file));

    expect($source)->toContain('ponytail:')
        ->and($source)->toContain('platform due-index')
        ->and($source)->toContain('boundary rule');
})->with([
    'app/Console/Commands/SweepConversations.php',
    'app/Console/Commands/PollProviderSessions.php',
    'app/Console/Commands/RelayLostJobs.php',
]);
