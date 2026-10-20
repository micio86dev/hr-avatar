<?php

use App\Jobs\DeliverWebhook;
use App\Webhooks\Outbound\DeliveryError;

/** The codes the deliveries endpoint documents for `last_error`, read from the committed contract. */
function documentedDeliveryErrorCodes(): array
{
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $description = $spec['paths']['/webhook-endpoints/{webhookEndpoint}/deliveries']['get']['description'];

    preg_match('/a short `last_error` code \((.+?)\)/s', $description, $list);
    preg_match_all('/`([^`]+)`/', $list[1] ?? '', $codes);

    return $codes[1];
}

it('has exactly the codes the deliveries endpoint documents, plus the code of a refused status', function () {
    $codes = [...array_map(fn (DeliveryError $error) => $error->value, DeliveryError::cases()), 'http_<status>'];

    expect($codes)->toEqualCanonicalizing(documentedDeliveryErrorCodes());
});

it('writes the code of a status the receiver refused with', function () {
    expect(DeliveryError::http(503))->toBe('http_503');
});

it('keeps every code short enough for the column', function (DeliveryError $error) {
    expect(strlen($error->value))->toBeLessThanOrEqual(32);
})->with(DeliveryError::cases());

it('is the only place the job names a code', function (DeliveryError $error) {
    $source = (string) file_get_contents((new ReflectionClass(DeliverWebhook::class))->getFileName());

    expect($source)->not->toContain("'{$error->value}'");
})->with(DeliveryError::cases());
