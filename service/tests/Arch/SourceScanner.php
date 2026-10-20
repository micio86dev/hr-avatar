<?php

namespace Tests\Arch;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Reads PHP source as tokens, so a comment or a docblock that talks about a thing is never mistaken for code
 * that does it. Lives in tests/Arch next to VocabularyScanner.
 */
final class SourceScanner
{
    /**
     * Quoted provider ids in code: where a provider is named, a branch on it is one step away.
     *
     * @param  list<string>  $roots  directories (or files) to scan
     * @param  list<string>  $ids  lower-case provider ids
     * @param  list<string>  $exempt  path fragments that may name a provider (the adapters, the registry)
     * @return list<array{class: string, file: string, line: int, literal: string}>
     */
    public function providerLiterals(array $roots, array $ids, array $exempt): array
    {
        $findings = [];

        foreach ($this->files($roots) as $file) {
            if (array_any($exempt, fn (string $fragment) => str_contains($file, $fragment))) {
                continue;
            }

            foreach ($this->tokens($file) as $token) {
                if ($token[0] === T_CONSTANT_ENCAPSED_STRING && in_array(strtolower(trim($token[1], '\'"')), $ids, true)) {
                    $findings[] = ['class' => $this->classOf($file), 'file' => $file, 'line' => $token[2], 'literal' => trim($token[1], '\'"')];
                }
            }
        }

        return $findings;
    }

    /**
     * Calls to functions that print a value: they leak whatever they are given into a response or a log.
     *
     * @param  list<string>  $roots
     * @param  list<string>  $functions
     * @return list<array{file: string, line: int, call: string}>
     */
    public function calls(array $roots, array $functions): array
    {
        $findings = [];

        foreach ($this->files($roots) as $file) {
            $tokens = array_values(array_filter($this->tokens($file), fn (array $t) => $t[0] !== T_WHITESPACE));

            foreach ($tokens as $i => $token) {
                $before = $tokens[$i - 1][0] ?? null;
                // `dd(` is a name; `\dd(` is a fully qualified one (a different token since PHP 8). Both are the global function.
                $isName = $token[0] === T_STRING || $token[0] === T_NAME_FULLY_QUALIFIED;
                $isCall = $isName && in_array(strtolower(ltrim($token[1], '\\')), $functions, true) && ($tokens[$i + 1][1] ?? null) === '('
                    && ! in_array($before, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);

                if ($isCall) {
                    $findings[] = ['file' => $file, 'line' => $token[2], 'call' => ltrim($token[1], '\\')];
                }
            }
        }

        return $findings;
    }

    /**
     * The string given to a `transport` (named argument or array key) wherever one is written; `<not a literal>`
     * stands for a value that is not a string literal.
     *
     * @param  list<string>  $roots
     * @param  list<string>  $exempt  path fragments of files that only carry a transport on (they never choose one)
     * @return list<string>
     */
    public function transportValues(array $roots, array $exempt = []): array
    {
        $values = [];

        foreach ($this->files($roots) as $file) {
            if (array_any($exempt, fn (string $fragment) => str_contains($file, $fragment))) {
                continue;
            }

            $tokens = array_values(array_filter($this->tokens($file), fn (array $t) => $t[0] !== T_WHITESPACE));

            foreach ($tokens as $i => $token) {
                $named = $token[0] === T_STRING && $token[1] === 'transport' && ($tokens[$i + 1][1] ?? null) === ':';
                $keyed = $token[0] === T_CONSTANT_ENCAPSED_STRING && trim($token[1], '\'"') === 'transport' && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_ARROW;
                $value = $tokens[$i + 2] ?? null;

                if ($named || $keyed) {
                    // Anything but a string literal cannot be checked against the list, so it is reported as unchecked.
                    $values[] = $value !== null && $value[0] === T_CONSTANT_ENCAPSED_STRING ? trim($value[1], '\'"') : '<not a literal>';
                }
            }
        }

        return $values;
    }

    /**
     * @return list<array{0: int, 1: string, 2: int}>
     */
    private function tokens(string $file): array
    {
        $tokens = [];

        foreach (\PhpToken::tokenize((string) file_get_contents($file)) as $token) {
            $tokens[] = [$token->id, $token->text, $token->line];
        }

        return $tokens;
    }

    private function classOf(string $file): string
    {
        $relative = preg_replace('#^.*?/(app|tests|config|routes|database)/#', '$1/', $file) ?? $file;

        return str_replace('/', '\\', ucfirst(substr($relative, 0, -4)));
    }

    /**
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function files(array $roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            if (is_file($root)) {
                $files[] = $root;

                continue;
            }

            foreach (is_dir($root) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) : [] as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
