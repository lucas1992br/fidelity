<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

test('the billing routines run daily in order, in São Paulo time and without overlapping', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events)->toHaveCount(3)
        ->and($events->map(fn (Event $event): array => [
            str($event->command)->afterLast("'artisan' ")->toString(),
            $event->expression,
        ])->all())->toBe([
            ['invoices:mark-overdue', '10 0 * * *'],
            ['cards:suspend-delinquent', '20 0 * * *'],
            ['invoices:generate', '0 1 * * *'],
        ]);

    $events->each(function (Event $event): void {
        expect((string) $event->timezone)->toBe('America/Sao_Paulo')
            ->and($event->withoutOverlapping)->toBeTrue();
    });
});

test('the example inspire command is gone', function () {
    expect(array_key_exists('inspire', Artisan::all()))->toBeFalse();
});
