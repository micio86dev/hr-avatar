<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('reaches PostgreSQL on the test database', function () {
    expect(DB::selectOne('select current_database() as name')->name)->toBe('hra_test');
});

it('reaches Redis', function () {
    expect(Redis::connection()->ping())->toBeTrue();
});
