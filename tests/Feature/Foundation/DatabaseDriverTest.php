<?php

use Illuminate\Support\Facades\DB;

test('the test suite runs on PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('testing');
});
