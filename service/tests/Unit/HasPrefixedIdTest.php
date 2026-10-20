<?php

use App\Accounts\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

class AvatarLikeModel extends Model
{
    use HasPrefixedId;

    protected static function idPrefix(): string
    {
        return 'avc_';
    }
}

class ConversationLikeModel extends Model
{
    use HasPrefixedId;

    protected static function idPrefix(): string
    {
        return 'conv_';
    }
}

it('makes an id of the model\'s prefix followed by a lowercase ULID', function () {
    expect((new AvatarLikeModel)->newUniqueId())->toMatch('/^avc_[0-9a-z]{26}\z/')
        ->and((new ConversationLikeModel)->newUniqueId())->toMatch('/^conv_[0-9a-z]{26}\z/');
});

it('fits the 31-character key column for the longest prefix', function () {
    expect(strlen((new ConversationLikeModel)->newUniqueId()))->toBe(31);
});

it('keeps the id a string and generates a fresh sortable one every time', function () {
    $model = new AvatarLikeModel;
    $first = $model->newUniqueId();
    usleep(2000);
    $second = $model->newUniqueId();

    expect($model->getKeyType())->toBe('string')
        ->and($model->getIncrementing())->toBeFalse()
        ->and($model->uniqueIds())->toBe(['id'])
        ->and($second)->not->toBe($first)->toBeGreaterThan($first);
});

it('accepts only its own prefix followed by a ULID as a valid id, so route binding can find it', function (mixed $value, bool $valid) {
    $model = new AvatarLikeModel;
    $value = $value === 'generated' ? $model->newUniqueId() : $value;

    expect((new ReflectionMethod($model, 'isValidUniqueId'))->invoke($model, $value))->toBe($valid);
})->with([
    'a generated id' => ['generated', true],
    'the prefix and a bare ulid' => ['avc_01arz3ndektsv4rrffq69g5fav', true],
    'a ulid without the prefix' => ['01arz3ndektsv4rrffq69g5fav', false],
    'another model\'s prefix' => ['conv_01arz3ndektsv4rrffq69g5fav', false],
    'the prefix and junk' => ['avc_not-a-ulid', false],
    'only the prefix' => ['avc_', false],
    'a number' => [123, false],
    'null' => [null, false],
]);
