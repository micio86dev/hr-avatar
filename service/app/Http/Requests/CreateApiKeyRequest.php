<?php

namespace App\Http\Requests;

use App\Keys\KeyMode;
use App\Keys\KeyType;
use App\Keys\OriginNormalizer;
use App\Keys\Scope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class CreateApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(KeyType::class)],
            'mode' => ['required', Rule::enum(KeyMode::class)],
            'scopes' => ['bail', 'required_if:type,secret', 'prohibited_if:type,publishable', 'array'],
            'scopes.*' => ['string', Rule::enum(Scope::class)],
            'allowed_origins' => ['bail', 'required_if:type,publishable', 'prohibited_if:type,secret', 'array', function (string $attribute, mixed $value, callable $fail): void {
                try {
                    OriginNormalizer::normalizeAll(self::strings($value));
                } catch (InvalidArgumentException $e) {
                    $fail($e->getMessage());
                }
            }],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function type(): KeyType
    {
        return KeyType::from($this->string('type')->toString());
    }

    public function mode(): KeyMode
    {
        return KeyMode::from($this->string('mode')->toString());
    }

    /**
     * @return list<string>
     */
    public function scopeList(): array
    {
        return array_values(array_unique(self::strings($this->validated('scopes', []))));
    }

    /**
     * @return list<string>|null
     */
    public function origins(): ?array
    {
        $origins = $this->validated('allowed_origins');

        return $origins === null ? null : OriginNormalizer::normalizeAll(self::strings($origins));
    }

    public function expiresAt(): ?Carbon
    {
        $expiresAt = $this->validated('expires_at');

        return is_string($expiresAt) ? Carbon::parse($expiresAt) : null;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || ! array_all($value, fn (mixed $item) => is_string($item))) {
            throw new InvalidArgumentException('A list of strings is required.');
        }

        return $value;
    }
}
