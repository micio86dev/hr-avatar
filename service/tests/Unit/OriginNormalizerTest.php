<?php

use App\Keys\OriginNormalizer;

it('normalizes to a lowercase scheme://host[:port] with no path or trailing slash', function (string $given, string $expected) {
    expect(OriginNormalizer::normalize($given))->toBe($expected);
})->with([
    'case and trailing slash' => ['HTTPS://App.Example.com/', 'https://app.example.com'],
    'port is kept' => ['https://app.example.com:8443', 'https://app.example.com:8443'],
    'default https port is dropped' => ['https://app.example.com:443', 'https://app.example.com'],
    'surrounding whitespace' => ["  https://app.example.com \n", 'https://app.example.com'],
    'loopback over http' => ['http://localhost', 'http://localhost'],
    'loopback with a port' => ['http://localhost:3000', 'http://localhost:3000'],
    'loopback address' => ['http://127.0.0.1:5173', 'http://127.0.0.1:5173'],
    'default http port is dropped on loopback' => ['http://localhost:80', 'http://localhost'],
]);

it('refuses a wildcard anywhere', function (string $given) {
    expect(fn () => OriginNormalizer::normalize($given))->toThrow(InvalidArgumentException::class, 'wildcard');
})->with(['*', 'https://*.example.com', 'https://app.*.com', 'https://app.example.com:*']);

it('refuses plain http except for loopback', function (string $given) {
    expect(fn () => OriginNormalizer::normalize($given))->toThrow(InvalidArgumentException::class, 'https');
})->with(['http://app.example.com', 'http://localhost.example.com', 'http://192.168.0.10:3000', 'http://0.0.0.0']);

it('refuses anything that is not a bare origin', function (string $given) {
    expect(fn () => OriginNormalizer::normalize($given))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => '',
    'no scheme' => 'app.example.com',
    'unknown scheme' => 'ftp://app.example.com',
    'javascript scheme' => 'javascript://app.example.com',
    'a path' => 'https://app.example.com/app',
    'a query' => 'https://app.example.com?x=1',
    'a fragment' => 'https://app.example.com#top',
    'credentials' => 'https://user:pass@app.example.com',
    'an opaque origin' => 'null',
    'a space in the host' => 'https://app example.com',
    'an empty label' => 'https://app..example.com',
    'a leading hyphen' => 'https://-app.example.com',
    'a port out of range' => 'https://app.example.com:70000',
    'a non-numeric port' => 'https://app.example.com:abc',
]);

it('normalizes a list, drops duplicates and enforces 1 to 20 entries', function () {
    expect(OriginNormalizer::normalizeAll(['https://A.example.com', 'https://a.example.com/', 'http://localhost:3000']))
        ->toBe(['https://a.example.com', 'http://localhost:3000']);

    expect(fn () => OriginNormalizer::normalizeAll([]))->toThrow(InvalidArgumentException::class, '1 to 20');

    $many = array_map(fn (int $i) => "https://app{$i}.example.com", range(1, 21));
    expect(fn () => OriginNormalizer::normalizeAll($many))->toThrow(InvalidArgumentException::class, '1 to 20');
    expect(OriginNormalizer::normalizeAll(array_slice($many, 0, 20)))->toHaveCount(20);
});
