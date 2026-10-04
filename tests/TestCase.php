<?php

namespace Tests;

use Database\Seeders\TestBaseSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Pagination\Paginator;

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

    /**
     * Livewire's `WithPagination` swaps the static paginator views while a
     * component renders and only restores them when the request ends, which
     * `Livewire::test()` never does; reset them so no test leaks into the next.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Paginator::defaultView('pagination::tailwind');
        Paginator::defaultSimpleView('pagination::simple-tailwind');
    }
}
