<?php

namespace App\Http\OpenApi;

use Dedoc\Scramble\Support\Generator\Contact;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Tag;

/**
 * Pins the parts of the document that Scramble would otherwise derive from the environment
 * (APP_NAME, APP_URL), so the committed openapi.json is identical on every machine and in CI,
 * and declares the tags that the operations use.
 */
final class DocumentIdentity
{
    public static function apply(OpenApi $document): void
    {
        $name = config('hr-avatar.name');

        $document->info->title = $name;
        $document->info->description = "HTTP API of the {$name} avatar conversation engine: create, drive and tear down real-time avatar conversations.";
        $document->info->contact = new Contact("{$name} API");
        $document->servers = [Server::make('/v1')];
        $document->tags = self::tagsUsedBy($document);
    }

    /**
     * @return list<Tag>
     */
    private static function tagsUsedBy(OpenApi $document): array
    {
        $names = [];

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                foreach ($operation->tags as $tag) {
                    $names[$tag] = true;
                }
            }
        }

        ksort($names);

        return array_map(fn (string $name) => new Tag($name), array_keys($names));
    }
}
