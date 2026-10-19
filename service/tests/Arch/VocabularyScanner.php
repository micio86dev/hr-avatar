<?php

namespace Tests\Arch;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds host-product vocabulary in source files.
 *
 * A line is split into identifier words (camelCase, snake_case and acronyms all break), so a term
 * is caught inside `$candidate_id` or `competencyScore` but not inside `projection`. Matching is
 * case-insensitive. This file lives in tests/Arch, which is exempt: it has to name the terms it bans.
 */
final class VocabularyScanner
{
    private const TERMS = [
        'beai',
        'participant', 'participants',
        'candidate', 'candidates',
        'competency', 'competencies',
        'bars',
        'interview', 'interviews',
        'project', 'projects',
        'organization', 'organizations',
        'organisation', 'organisations',
        'assessment', 'assessments',
        'score', 'scores',
    ];

    /**
     * Identifiers a provider's own wire format dictates, so an adapter can send them: removed from
     * a line before it is read, which leaves the rest of that line scanned.
     */
    private const PROVIDER_WIRE_IDENTIFIERS = ['participant_left_timeout'];

    private const EXEMPT_DIRECTORY = '/tests/Arch/';

    /**
     * @param  list<string>  $roots  directories to scan recursively; a missing one is skipped
     * @return list<array{file: string, line: int, term: string}>
     */
    public function scan(array $roots): array
    {
        $findings = [];

        foreach ($roots as $root) {
            foreach ($this->files($root) as $file) {
                array_push($findings, ...$this->scanFile($file));
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function files(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $entry) {
            if ($entry->isFile() && ! str_contains($entry->getPathname(), self::EXEMPT_DIRECTORY)) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<array{file: string, line: int, term: string}>
     */
    private function scanFile(string $file): array
    {
        $findings = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
            preg_match_all('/[A-Z]+(?![a-z])|[A-Z]?[a-z]+/', str_replace(self::PROVIDER_WIRE_IDENTIFIERS, '', $line), $matches);

            foreach (array_unique(array_map('strtolower', $matches[0])) as $word) {
                if (in_array($word, self::TERMS, true)) {
                    $findings[] = ['file' => $file, 'line' => $index + 1, 'term' => $word];
                }
            }
        }

        return $findings;
    }
}
