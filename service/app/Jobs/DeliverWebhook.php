<?php

namespace App\Jobs;

use App\Accounts\CurrentAccount;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\Outbound\DeliveryStatus;
use App\Webhooks\Outbound\DestinationNotAllowed;
use App\Webhooks\Outbound\OutboundHostGuard;
use App\Webhooks\Outbound\WebhookSigner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes one attempt to deliver one webhook, and schedules the next if it failed.
 *
 * The job carries the account id and the delivery id and nothing else: the secret, the URL and the body
 * are read where it runs, under the account, so none of them can reach the queue or a failed-job record. It does not use the queue's own
 * retries (`tries` is 1): an attempt is recorded on the row, and a failed one queues this job again with
 * the next delay, 10 s, 60 s, 300 s, 1800 s and 7200 s, for six attempts in all. Each attempt is signed
 * afresh, sent to the addresses the destination guard just checked (the connection is pinned to them, so
 * a name that now resolves elsewhere cannot redirect it), with a 10 s limit and without following redirects.
 *
 * Every write is conditional on the row still being `pending` with the attempt count this run read, so a
 * duplicated job cannot count an attempt twice. What is recorded about a failure is a code from a closed
 * set, never a response body, a transport message or the secret.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    /** Seconds to wait after the first, second, ... failed attempt. The attempt after the last one is the final one. */
    private const BACKOFF_SECONDS = [10, 60, 300, 1800, 7200];

    private const REQUEST_TIMEOUT_SECONDS = 10;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $accountId, public readonly string $deliveryId) {}

    public function handle(WebhookSigner $signer, OutboundHostGuard $guard): void
    {
        CurrentAccount::runAs($this->accountId, function () use ($signer, $guard): void {
            $delivery = WebhookDelivery::query()->find($this->deliveryId);

            // Finished (or gone, or not this account's): a job delivered twice by the queue has nothing to do.
            if ($delivery !== null && $delivery->status === DeliveryStatus::Pending) {
                $this->attempt($delivery, $signer, $guard);
            }
        });
    }

    /**
     * Safety net for a job that died before it recorded its attempt: the delivery stops, as failed.
     */
    public function failed(Throwable $exception): void
    {
        // The exception can say anything (a transport message may hold a URL), so only its class is logged.
        Log::error('Webhook delivery job failed', ['delivery_id' => $this->deliveryId, 'exception' => $exception::class]);

        // Conditional on the row still being pending, so a finished delivery is never touched.
        CurrentAccount::runAs($this->accountId, fn () => WebhookDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('status', DeliveryStatus::Pending->value)
            ->update(['status' => DeliveryStatus::Failed->value, 'last_error' => 'internal_error', 'next_attempt_at' => null, 'completed_at' => now()]));
    }

    private function attempt(WebhookDelivery $delivery, WebhookSigner $signer, OutboundHostGuard $guard): void
    {
        $endpoint = WebhookEndpoint::query()->find($delivery->webhook_endpoint_id);

        if ($endpoint === null || ! $endpoint->enabled) {
            $this->write($delivery, ['status' => DeliveryStatus::Failed->value, 'last_error' => 'endpoint_disabled', 'next_attempt_at' => null, 'completed_at' => now()]);

            return;
        }

        [$status, $error] = $this->send($delivery, $endpoint, $signer, $guard);
        $attempts = $delivery->attempts + 1;
        $outcome = ['attempts' => $attempts, 'last_attempt_at' => now(), 'last_response_status' => $status, 'last_error' => $error];

        if ($error === null) {
            $this->write($delivery, [...$outcome, 'status' => DeliveryStatus::Succeeded->value, 'next_attempt_at' => null, 'completed_at' => now()]);

            return;
        }

        if ($attempts > count(self::BACKOFF_SECONDS)) {
            $this->write($delivery, [...$outcome, 'status' => DeliveryStatus::Failed->value, 'next_attempt_at' => null, 'completed_at' => now()]);

            return;
        }

        $delay = self::BACKOFF_SECONDS[$attempts - 1];

        if ($this->write($delivery, [...$outcome, 'next_attempt_at' => now()->addSeconds($delay)])) {
            self::dispatch($this->accountId, $delivery->id)->delay($delay);
        }
    }

    /**
     * One request. Returns the response status (null when there was none) and the error code (null on success).
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function send(WebhookDelivery $delivery, WebhookEndpoint $endpoint, WebhookSigner $signer, OutboundHostGuard $guard): array
    {
        try {
            $addresses = $guard->vettedAddresses($endpoint->url);
        } catch (DestinationNotAllowed) {
            return [null, 'destination_not_allowed'];
        }

        // Fail closed. An enforced guard that found no address for a host name (it did not resolve, or the answer
        // was empty) leaves nothing to pin, and the client would then resolve the name again at connect time: an
        // address nobody judged. The attempt is refused; the next one resolves afresh.
        if ($addresses === [] && $guard->enforced()) {
            return [null, 'destination_not_allowed'];
        }

        // The timestamp of THIS attempt: a retry is signed again, with the same body and event id.
        $timestamp = now()->getTimestamp();

        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => false, ...$this->pin($endpoint->url, $addresses)])
                ->withHeaders([
                    'X-HRA-Signature' => $signer->sign($endpoint->secret, $timestamp, $delivery->payload),
                    'X-HRA-Timestamp' => (string) $timestamp,
                    'X-HRA-Event-Id' => $delivery->event_id,
                    'X-HRA-Event-Type' => $delivery->event_type->value,
                ])
                // The stored bytes, as they are: the signature covers exactly these.
                ->withBody($delivery->payload, 'application/json')
                ->post($endpoint->url);
        } catch (ConnectionException $failure) {
            return [null, str_contains($failure->getMessage(), 'cURL error 28') || str_contains(strtolower($failure->getMessage()), 'timed out') ? 'timeout' : 'connection_failed'];
        }

        return $response->successful() ? [$response->status(), null] : [$response->status(), 'http_'.$response->status()];
    }

    /**
     * The cURL option that makes the connection go to the addresses that were checked, whatever DNS says now.
     * An address in the URL needs none, and neither does a destination that is not enforced (no addresses are
     * vetted there). An enforced host with no vetted address never gets here: `send()` refuses it first.
     *
     * @param  list<string>  $addresses
     * @return array<string, array<int, list<string>>>
     */
    private function pin(string $url, array $addresses): array
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        if ($addresses === [] || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $port = parse_url($url, PHP_URL_PORT) ?? 443;
        $list = implode(',', array_map(fn (string $address) => str_contains($address, ':') ? "[{$address}]" : $address, $addresses));

        return ['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$list}"]]];
    }

    /**
     * The one write of an attempt: it lands only while the row is still pending at the attempt count this run read.
     *
     * @param  array<string, mixed>  $values
     */
    private function write(WebhookDelivery $delivery, array $values): bool
    {
        return WebhookDelivery::query()
            ->whereKey($delivery->id)
            ->where('status', DeliveryStatus::Pending->value)
            ->where('attempts', $delivery->attempts)
            ->update($values) === 1;
    }
}
