<?php

use Illuminate\Support\Facades\DB;

test('f_unaccent strips accents for accent-insensitive search', function () {
    expect(DB::scalar("select f_unaccent('Conceição')"))->toBe('Conceicao');
});

test('pg_trgm is enabled', function () {
    $similarity = DB::scalar("select similarity('maria', 'mária')");

    expect((float) $similarity)->toBeGreaterThan(0.0);
});
