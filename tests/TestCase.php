<?php

namespace Tests;

use Database\Seeders\TestBaseSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the base data (lookups, roles, financial categories) when the
     * test database is refreshed, so every feature test starts with it.
     */
    protected bool $seed = true;

    /**
     * @var class-string
     */
    protected string $seeder = TestBaseSeeder::class;
}
