<?php

use App\Http\Requests\PaginationRequest;
use App\Http\Resources\PagedCollection;
use App\Models\Account;
use App\Models\ApiKey;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

/**
 * @mixin ApiKey
 */
class PaginationProbeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return ['id' => $this->id, 'created_at' => $this->created_at?->toIso8601String()];
    }
}

beforeEach(function () {
    $this->account = Account::factory()->create();
    [, $this->raw] = issueKey($this->account);

    Route::middleware(['api', 'auth.secret'])->get('/v1/_pages', fn (PaginationRequest $request) => new PagedCollection($request->page(ApiKey::query()), PaginationProbeResource::class));
});

function page(string $query = '', ?string $raw = null)
{
    return test()->getJson('/v1/_pages'.$query, ['Authorization' => 'Bearer '.($raw ?? test()->raw)]);
}

/**
 * Adds keys to the account so that, with the key that authenticates the test, it holds $total.
 */
function holdKeys(int $total): void
{
    inAccount(test()->account, function () use ($total) {
        foreach (range(1, $total - 1) as $i) {
            ApiKey::factory()->create(['created_at' => now()->subMinutes($i)]);
        }
    });
}

it('serves the default page of 25 with a cursor to the rest', function () {
    holdKeys(30);

    $response = page()->assertOk();

    expect(array_keys($response->json()))->toBe(['data', 'next_cursor'])
        ->and($response->json('data'))->toHaveCount(25)
        ->and($response->json('next_cursor'))->toBeString()->not->toBeEmpty();
});

it('follows the cursor to the remaining items without duplicates', function () {
    holdKeys(30);

    $first = page()->json();
    $second = page('?cursor='.urlencode($first['next_cursor']))->assertOk()->json();

    $ids = array_column([...$first['data'], ...$second['data']], 'id');

    expect($second['data'])->toHaveCount(5)
        ->and($second['next_cursor'])->toBeNull()
        ->and($ids)->toHaveCount(30)
        ->and(array_unique($ids))->toHaveCount(30);
});

it('honours a limit from 1 to 100', function (int $limit, int $returned) {
    holdKeys(12);

    page("?limit={$limit}")->assertOk()->assertJsonCount($returned, 'data');
})->with([
    'one' => [1, 1],
    'ten' => [10, 10],
    'more than exist' => [100, 12],
]);

it('refuses a limit outside 1 to 100 or not an integer', function (string $limit) {
    $response = page("?limit={$limit}");

    $response->assertStatus(422);
    expect($response->json('code'))->toBe('validation_failed')
        ->and($response->json('errors'))->toHaveKey('limit');
})->with(['zero' => '0', 'negative' => '-3', 'too large' => '500', 'one hundred and one' => '101', 'text' => 'abc', 'decimal' => '1.5']);

it('refuses a malformed cursor instead of silently serving the first page', function (string $cursor) {
    holdKeys(30);

    $response = page('?cursor='.urlencode($cursor));

    $response->assertStatus(422);
    expect($response->json('code'))->toBe('validation_failed')
        ->and($response->json('errors'))->toHaveKey('cursor');
})->with([
    'not base64 json' => 'garbage',
    'a json scalar' => fn () => rtrim(strtr(base64_encode('7'), '+/', '-_'), '='),
    'a json list' => fn () => rtrim(strtr(base64_encode('[]'), '+/', '-_'), '='),
    'missing direction' => fn () => rtrim(strtr(base64_encode(json_encode(['created_at' => '2026-01-01 00:00:00', 'id' => 'x'])), '+/', '-_'), '='),
    'a date that is not a date' => fn () => rtrim(strtr(base64_encode(json_encode(['created_at' => 'yesterday-ish', 'id' => 'x', '_pointsToNextItems' => true])), '+/', '-_'), '='),
    'a calendar date that does not exist' => fn () => rtrim(strtr(base64_encode(json_encode(['created_at' => '2026-02-30T10:00:00.000000Z', 'id' => 'x', '_pointsToNextItems' => true])), '+/', '-_'), '='),
    'a direction that is not a boolean' => fn () => rtrim(strtr(base64_encode(json_encode(['created_at' => '2026-01-01T10:00:00.000000Z', 'id' => 'x', '_pointsToNextItems' => 1])), '+/', '-_'), '='),
    'an id that is not a string' => fn () => rtrim(strtr(base64_encode(json_encode(['created_at' => '2026-01-01 00:00:00', 'id' => ['x'], '_pointsToNextItems' => true])), '+/', '-_'), '='),
    'an unexpected extra member' => fn () => rtrim(strtr(base64_encode(json_encode(['created_at' => '2026-01-01 00:00:00', 'id' => 'x', 'other' => 1, '_pointsToNextItems' => true])), '+/', '-_'), '='),
]);

it('orders newest first and breaks ties by id descending', function () {
    $tied = now()->addHour()->startOfSecond();
    $ids = inAccount($this->account, fn () => collect(range(1, 3))
        ->map(fn () => ApiKey::factory()->create(['created_at' => $tied])->id)
        ->all());

    $listed = collect(page('?limit=100')->json('data'))->pluck('id');

    expect($listed->take(3)->all())->toBe(array_reverse($ids));
});

it('never lists another account\'s rows', function () {
    $other = Account::factory()->create();
    [$foreign] = issueKey($other);

    $listed = collect(page()->json('data'))->pluck('id');

    expect($listed)->not->toContain($foreign->id)->and($listed)->toHaveCount(1);
});
