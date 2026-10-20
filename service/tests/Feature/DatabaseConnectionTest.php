<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Redis;

it('reaches PostgreSQL on the test database', function () {
    // The parallel runner gives each worker a database of its own, named `<database>_test_<token>`; the
    // name is still compared exactly, so a run against any other database fails.
    $token = ParallelTesting::token();

    expect(DB::selectOne('select current_database() as name')->name)->toBe($token === false ? 'hra_test' : "hra_test_test_{$token}");
});

it('reaches Redis', function () {
    expect(Redis::connection()->ping())->toBeTrue();
});
