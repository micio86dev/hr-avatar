<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Review rv01..rv02 (brief): the failed-job driver is `database-uuids`, so a worker that gives up on a job writes a row to
// `failed_jobs`; with no table, the worker would crash on the very failure it was recording.
it('has the table the failed-job driver writes to', function () {
    expect(config('queue.failed.driver'))->toBe('database-uuids')
        ->and(Schema::hasColumns(config('queue.failed.table'), ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']))->toBeTrue();
});

it('records a failed job, and queue:retry can find it again', function () {
    $uuid = (string) Str::uuid();

    app('queue.failer')->log('redis', 'default', json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\Example']), new RuntimeException('gave up'));

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->count())->toBe(1)
        ->and(app('queue.failer')->find($uuid))->not->toBeNull();
});
