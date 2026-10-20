<?php

namespace Database\Seeders;

use App\Models\LlmModel;
use Illuminate\Database\Seeder;

/**
 * The platform language-model catalogue. The list below is the source of truth: running the
 * seeder again (every deploy does) restores these rows, `enabled` included, so a model is
 * retired by editing this file. `model` is the exact vendor string, sent to the provider verbatim.
 */
class LlmModelSeeder extends Seeder
{
    private const GEMINI_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/openai/';

    public function run(): void
    {
        foreach ($this->catalogue() as $entry) {
            LlmModel::query()->updateOrCreate(['id' => $entry['id']], $entry);
        }
    }

    /**
     * @return list<array{id: string, provider: string, model: string, display_name: string, base_url: string, enabled: bool}>
     */
    private function catalogue(): array
    {
        return [
            [
                'id' => 'gemini-flash-lite',
                'provider' => 'gemini',
                'model' => 'gemini-3.1-flash-lite-preview',
                'display_name' => 'Gemini 3.1 Flash Lite Preview',
                'base_url' => self::GEMINI_BASE_URL,
                'enabled' => true,
            ],
            [
                'id' => 'gemini-pro',
                'provider' => 'gemini',
                'model' => 'gemini-3.1-pro-preview',
                'display_name' => 'Gemini 3.1 Pro Preview',
                'base_url' => self::GEMINI_BASE_URL,
                'enabled' => true,
            ],
        ];
    }
}
