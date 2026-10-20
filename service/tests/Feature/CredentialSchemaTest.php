<?php

use App\Models\Account;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\ProviderCredential;
use Database\Seeders\LlmModelSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(fn () => $this->account = Account::factory()->create());

it('keeps one provider credential per account and provider', function () {
    inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'tavus', 'api_key' => 'tv_one']));

    // A savepoint, so the failed insert does not abort the test's own transaction.
    expect(fn () => DB::transaction(fn () => inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'tavus', 'api_key' => 'tv_two']))))
        ->toThrow(QueryException::class);

    inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'heygen', 'api_key' => 'hg_one']));
    inAccount(Account::factory()->create(), fn () => ProviderCredential::create(['provider' => 'tavus', 'api_key' => 'tv_other']));

    expect(DB::table('provider_credentials')->count())->toBe(3);
});

it('keeps one LLM credential per account and LLM provider', function () {
    inAccount($this->account, fn () => LlmCredential::create(['llm_provider' => 'gemini', 'api_key' => 'g_one']));

    expect(fn () => DB::transaction(fn () => inAccount($this->account, fn () => LlmCredential::create(['llm_provider' => 'gemini', 'api_key' => 'g_two']))))
        ->toThrow(QueryException::class);

    inAccount(Account::factory()->create(), fn () => LlmCredential::create(['llm_provider' => 'gemini', 'api_key' => 'g_other']));

    expect(DB::table('llm_credentials')->count())->toBe(2);
});

it('stores a credential encrypted at rest and decrypts it back', function (string $model, array $attributes) {
    $plaintext = 'sk_live_super_secret_value_1234';

    $credential = inAccount($this->account, fn () => $model::create([...$attributes, 'api_key' => $plaintext]));

    $raw = DB::table($credential->getTable())->where('id', $credential->id)->value('api_key');
    // Only the whole plaintext is asserted absent: the cast uses a random IV, so a short fragment such as the last four
    // characters can appear in the ciphertext by chance.
    expect($raw)->not->toBeEmpty()->not->toContain($plaintext)
        ->and(inAccount($this->account, fn () => $model::findOrFail($credential->id)->api_key))->toBe($plaintext);
})->with([
    'provider credential' => [ProviderCredential::class, ['provider' => 'tavus']],
    'LLM credential' => [LlmCredential::class, ['llm_provider' => 'gemini']],
]);

it('scopes credentials to the bound account and stamps the owner', function () {
    $other = Account::factory()->create();
    $own = inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'tavus', 'api_key' => 'tv_mine_1234567']));
    inAccount($other, fn () => ProviderCredential::create(['provider' => 'heygen', 'api_key' => 'hg_theirs_1234567']));

    expect($own->account_id)->toBe($this->account->id)
        ->and(inAccount($this->account, fn () => ProviderCredential::pluck('provider')->all()))->toBe(['tavus'])
        ->and(fn () => ProviderCredential::count())->toThrow(LogicException::class);
});

it('refuses a credential for an account that does not exist', function () {
    expect(fn () => DB::table('provider_credentials')->insert([
        'id' => (string) Str::ulid(), 'account_id' => (string) Str::ulid(),
        'provider' => 'tavus', 'api_key' => 'x', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('has a platform LLM catalogue with no account column', function () {
    expect(Schema::hasTable('llm_models'))->toBeTrue()
        ->and(Schema::hasColumn('llm_models', 'account_id'))->toBeFalse()
        ->and(Schema::getColumnListing('llm_models'))->toEqualCanonicalizing(['id', 'provider', 'model', 'display_name', 'base_url', 'enabled', 'created_at', 'updated_at']);
});

it('seeds the LLM catalogue idempotently', function () {
    $this->seed(LlmModelSeeder::class);
    $first = LlmModel::query()->orderBy('id')->get(['id', 'provider', 'model', 'display_name', 'base_url', 'enabled'])->toArray();

    $this->seed(LlmModelSeeder::class);

    expect(LlmModel::count())->toBe(count($first))->toBeGreaterThan(0)
        ->and(LlmModel::query()->orderBy('id')->get(['id', 'provider', 'model', 'display_name', 'base_url', 'enabled'])->toArray())->toBe($first);
});

it('seeds entries with a provider, an exact model string and an https base URL', function () {
    $this->seed(LlmModelSeeder::class);

    foreach (LlmModel::all() as $model) {
        expect($model->provider)->not->toBeEmpty()
            ->and($model->model)->not->toBeEmpty()
            ->and($model->base_url)->toStartWith('https://')
            ->and($model->enabled)->toBeTrue();
    }
    expect(LlmModel::where('provider', 'gemini')->exists())->toBeTrue();
});

it('lets the catalogue file win when the seeder runs again', function () {
    $this->seed(LlmModelSeeder::class);
    $model = LlmModel::firstOrFail();
    $model->update(['display_name' => 'Tampered', 'enabled' => false]);

    $this->seed(LlmModelSeeder::class);

    expect($model->fresh()->display_name)->not->toBe('Tampered')->and($model->fresh()->enabled)->toBeTrue();
});
