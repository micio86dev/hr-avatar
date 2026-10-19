<?php

use App\Accounts\BelongsToAccount;
use App\Models\Account;
use App\Models\LlmModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Tests\Arch\SourceScanner;

// Dataset providers run before the application boots, so paths here come from this file, not base_path().

/**
 * Every row belongs to an account except the tenant root and the platform catalogue, and whatever holds a secret
 * or a payload is kept out of serialization.
 */
function modelClasses(): array
{
    return array_map(fn (string $file) => 'App\\Models\\'.basename($file, '.php'), glob(dirname(__DIR__, 2).'/app/Models/*.php') ?: []);
}

/** What each model must keep out of `toArray()` / `toJson()`. A model with nothing to hide is listed with `[]`. */
const HIDDEN_ATTRIBUTES = [
    'App\\Models\\Account' => [],
    'App\\Models\\ApiKey' => ['key_hash'],
    'App\\Models\\AvatarConfig' => [],
    'App\\Models\\Conversation' => ['provider_session_ref', 'provider_context_ref', 'callback_token_hash'],
    'App\\Models\\ConversationEvent' => [],
    'App\\Models\\LlmCredential' => ['api_key'],
    'App\\Models\\LlmModel' => [],
    'App\\Models\\ProviderCredential' => ['api_key'],
    'App\\Models\\ProviderResource' => [],
    'App\\Models\\ProviderWebhookReceipt' => ['payload'],
    'App\\Models\\WebhookDelivery' => ['payload'],
    'App\\Models\\WebhookEndpoint' => ['secret'],
];

it('has every model of app/Models in the rules below, so a new one is judged', function () {
    expect(array_keys(HIDDEN_ATTRIBUTES))->toEqualCanonicalizing(modelClasses());
});

it('scopes every model to an account, except the tenant root and the platform catalogue', function (string $class) {
    $uses = class_uses_recursive($class);

    if (in_array($class, [Account::class, LlmModel::class], true)) {
        expect($uses)->not->toContain(BelongsToAccount::class);

        return;
    }

    expect($uses)->toContain(BelongsToAccount::class);
})->with(fn () => modelClasses());

it('hides what holds a secret or a payload', function (string $class, array $hidden) {
    /** @var Model $model */
    $model = new $class;

    expect(array_diff($hidden, $model->getHidden()))->toBe([]);
})->with(fn () => collect(HIDDEN_ATTRIBUTES)->map(fn (array $hidden, string $class) => [$class, $hidden])->all());

it('hides every attribute it encrypts', function (string $class) {
    /** @var Model $model */
    $model = new $class;
    $encrypted = collect($model->getCasts())->filter(fn (string $cast) => str_starts_with($cast, 'encrypted'))->keys()->all();

    expect(array_diff($encrypted, $model->getHidden()))->toBe([]);
})->with(fn () => modelClasses());

it('does not mass assign the account', function (string $class) {
    /** @var Model $model */
    $model = new $class;

    expect($model->isFillable('account_id'))->toBeFalse();
})->with(fn () => modelClasses());

it('prints nothing in app/: no var_export, print_r, var_dump, dd or dump', function () {
    $findings = (new SourceScanner)->calls([base_path('app')], ['var_export', 'print_r', 'var_dump', 'dd', 'dump']);

    expect($findings)->toBe([], collect($findings)->map(fn (array $f) => "{$f['file']}:{$f['line']} calls {$f['call']}()")->implode("\n"));
});

it('finds a printing call in code, and not in a comment, a method or a name', function () {
    $root = sys_get_temp_dir().'/calls-'.bin2hex(random_bytes(6));
    File::makeDirectory($root, recursive: true);
    File::put($root.'/Bad.php', "<?php\n\nvar_export(\$x);\nprint_r(\$x);\ndd(\$x);\n\\var_dump(\$x);\n\\dd(\$x);\n");
    File::put($root.'/Good.php', "<?php\n\n// var_export(\$x);\n\$this->dd(\$x);\nFoo::dump(\$x);\nfunction dump() {}\n\$y = 'print_r(';\n\$z = my_dd(\$x);\n\$w = \\Vendor\\dd(\$x);\n");

    $findings = (new SourceScanner)->calls([$root], ['var_export', 'print_r', 'var_dump', 'dd', 'dump']);
    File::deleteDirectory($root);

    expect(collect($findings)->pluck('call')->all())->toBe(['var_export', 'print_r', 'dd', 'var_dump', 'dd']);
});
