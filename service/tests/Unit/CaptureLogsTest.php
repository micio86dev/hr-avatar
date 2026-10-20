<?php

use Illuminate\Support\Facades\Log;

it('keeps a log context that cannot be encoded as JSON, so an absence assertion has read it', function () {
    $logs = captureLogs();

    // Invalid UTF-8: json_encode() returns false for this context.
    Log::error('Provider request failed', ['detail' => "token=s3cr3t-\xB1\x31"]);

    expect(json_encode(['detail' => "token=s3cr3t-\xB1\x31"]))->toBeFalse()
        ->and(implode("\n", $logs->getArrayCopy()))->toContain('Provider request failed')->toContain('s3cr3t-');
});

it('still records a context that encodes, and an exception\'s message', function () {
    $logs = captureLogs();

    Log::error('Failed', ['id' => 'conv_1', 'exception' => new RuntimeException('boom')]);

    expect(implode("\n", $logs->getArrayCopy()))->toContain('conv_1')->toContain('boom');
});
