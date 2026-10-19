<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * Feature and Unit tests boot the application against the PostgreSQL test database
 * (json, jsonb and CHECK constraints need a real engine). Every outbound HTTP call
 * must be faked: a stray request is a test failure, never a network call.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature', 'Unit');
