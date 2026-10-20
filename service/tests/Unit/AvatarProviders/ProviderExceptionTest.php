<?php

use App\AvatarProviders\FailureClass;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\TranscriptNotReady;
use App\AvatarProviders\TranscriptShapeError;

it('builds the generic message from the status alone', function (int $status) {
    $exception = ProviderException::fromStatus($status);

    expect($exception->getMessage())->toBe("Provider request failed (status {$status})")
        ->and($exception->status())->toBe($status);
})->with([400, 401, 404, 422, 429, 500, 502, 503]);

it('classifies a failure from its status', function (int $status, FailureClass $class, string $statusClass) {
    $exception = ProviderException::fromStatus($status);

    expect($exception->failureClass())->toBe($class)->and($exception->statusClass())->toBe($statusClass);
})->with([
    'rate limited' => [429, FailureClass::Throttle, '4xx'],
    'bad request' => [400, FailureClass::ClientError, '4xx'],
    'unauthorized' => [401, FailureClass::ClientError, '4xx'],
    'not found' => [404, FailureClass::ClientError, '4xx'],
    'unprocessable' => [422, FailureClass::ClientError, '4xx'],
    'server error' => [500, FailureClass::Upstream, '5xx'],
    'bad gateway' => [502, FailureClass::Upstream, '5xx'],
]);

it('can be marked as a throttle whatever the status, for the concurrency limit a 4xx reports', function () {
    $exception = ProviderException::throttled(409);

    expect($exception->failureClass())->toBe(FailureClass::Throttle)
        ->and($exception->status())->toBe(409)
        ->and($exception->getMessage())->toBe('Provider request failed (status 409)');
});

it('has a message for a failure with no response, a timeout or a body that makes no sense', function () {
    $exception = ProviderException::noResponse();

    expect($exception->getMessage())->toBe('Provider request failed (no response)')
        ->and($exception->failureClass())->toBe(FailureClass::Upstream)
        ->and($exception->status())->toBeNull()
        ->and($exception->statusClass())->toBe('none');
});

it('cannot carry the exception it was raised from, whose request and response hold the key and the body', function () {
    $exception = ProviderException::fromStatus(500);

    expect($exception->getPrevious())->toBeNull()
        ->and((new ReflectionClass(ProviderException::class))->getConstructor()?->isPrivate())->toBeTrue();
});

it('logs the status class and nothing else', function () {
    expect(ProviderException::fromStatus(503)->context())->toBe(['status_class' => '5xx'])
        ->and(ProviderException::noResponse()->context())->toBe(['status_class' => 'none']);
});

it('names the three failure classes', function () {
    expect(array_map(fn (FailureClass $class) => $class->value, FailureClass::cases()))->toBe(['throttle', 'upstream', 'client_error']);
});

it('has transcript errors that say what happened without quoting the provider', function () {
    expect(TranscriptNotReady::class)->toExtend(RuntimeException::class)
        ->and((new TranscriptShapeError('A transcript row has an unknown role.'))->getMessage())->toBe('A transcript row has an unknown role.')
        ->and(TranscriptShapeError::class)->toExtend(RuntimeException::class);
});
