<?php

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\CardStatusLog;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\User;
use App\Services\CustomerTimeline;
use App\Support\Customers\TimelineEvent;

test('a holder with sign-up, replacement, automatic suspension, payment and note gets the five events from newest to oldest', function () {
    $operator = User::factory()->create(['name' => 'Paulo Mendes']);
    $customer = Customer::factory()->create();
    PlanChange::factory()->for($customer)->for($operator)->create(['created_at' => '2024-02-14 09:00:00']);
    $old = Card::factory()->lost()->for($customer)->create(['number' => '000087', 'assigned_at' => '2024-02-14']);
    Card::factory()->active()->for($customer)->create(['number' => '000123', 'assigned_at' => '2025-06-03']);
    CardStatusLog::factory()->for($old)->for($operator)->transition(CardStatus::Active, CardStatus::Lost)
        ->create(['reason' => 'Perda relatada', 'created_at' => '2025-06-03 10:00:00']);
    $invoice = Invoice::factory()->for($customer)->forMonth('2026-08')->paid()->create(['created_at' => '2026-07-25 01:00:00']);
    Payment::factory()->for($invoice)->for($operator)->create(['created_at' => '2026-08-20 15:00:00']);
    CardStatusLog::factory()->for($customer->cards()->where('number', '000123')->sole())->bySystem()
        ->transition(CardStatus::Active, CardStatus::Suspended)
        ->create(['reason' => 'Suspensão automática: 32 dias em atraso', 'created_at' => '2026-09-01 00:20:00']);
    CustomerNote::factory()->for($customer)->for($operator)->create(['body' => 'Prefere WhatsApp', 'created_at' => '2026-09-10 11:00:00']);

    $events = app(CustomerTimeline::class)->for($customer)
        ->reject(fn (TimelineEvent $event): bool => $event->type === CustomerTimeline::INVOICE_ISSUED);

    expect($events->pluck('type')->all())->toBe([
        CustomerTimeline::NOTE,
        CustomerTimeline::CARD_SUSPENDED,
        CustomerTimeline::PAYMENT,
        CustomerTimeline::CARD_REPLACED,
        CustomerTimeline::ENROLLMENT,
    ])
        ->and($events->pluck('user')->all())->toBe(['Paulo Mendes', 'sistema', 'Paulo Mendes', 'Paulo Mendes', 'Paulo Mendes'])
        ->and($events->firstWhere('type', CustomerTimeline::CARD_REPLACED)->description)->toBe('000087 → 000123 · Extraviado · Perda relatada')
        ->and($events->firstWhere('type', CustomerTimeline::CARD_SUSPENDED)->description)->toBe('Suspensão automática: 32 dias em atraso');
});

test('the issued invoices are part of the timeline', function () {
    $customer = Customer::factory()->create();
    Invoice::factory()->for($customer)->forMonth('2026-10')->create(['amount' => '89.90']);

    $event = app(CustomerTimeline::class)->for($customer)->sole();

    expect($event->type)->toBe(CustomerTimeline::INVOICE_ISSUED)
        ->and($event->title)->toBe('Mensalidade 10/2026 gerada')
        ->and($event->user)->toBe('sistema');
});

test('a refund shows as its own event after the payment', function () {
    $admin = User::factory()->create(['name' => 'Dra. Lúcia Prado']);
    $customer = Customer::factory()->create();
    $invoice = Invoice::factory()->for($customer)->create();
    $payment = Payment::factory()->for($invoice)->create(['created_at' => '2026-09-10 10:00:00']);
    $payment->forceFill(['refunded_at' => '2026-09-12 16:00:00', 'refunded_by_user_id' => $admin->id, 'refund_reason' => 'Pago em duplicidade'])->save();
    $payment->refresh();

    $events = app(CustomerTimeline::class)->for($customer)
        ->reject(fn (TimelineEvent $event): bool => $event->type === CustomerTimeline::INVOICE_ISSUED);

    expect($events->pluck('type')->all())->toBe([CustomerTimeline::REFUND, CustomerTimeline::PAYMENT])
        ->and($events->first()->user)->toBe('Dra. Lúcia Prado')
        ->and($events->first()->description)->toContain("Recibo nº {$payment->receipt_number}", 'Pago em duplicidade');
});

test('plan changes and re-enrollment are told apart from the sign-up', function () {
    $customer = Customer::factory()->create();
    $familiar = Plan::factory()->create(['name' => 'Familiar']);
    PlanChange::factory()->for($customer)->create(['created_at' => '2024-01-01 10:00:00']);
    PlanChange::factory()->for($customer)->create([
        'from_plan_id' => $customer->plan_id, 'from_price' => '49.90', 'to_plan_id' => $familiar->id,
        'reason' => 'Mais dependentes', 'created_at' => '2025-01-01 10:00:00',
    ]);
    PlanChange::factory()->for($customer)->create([
        'from_plan_id' => $familiar->id, 'to_plan_id' => $familiar->id, 'reason' => 'Reingresso', 'created_at' => '2026-01-01 10:00:00',
    ]);

    expect(app(CustomerTimeline::class)->for($customer)->pluck('type')->all())->toBe([
        CustomerTimeline::REENROLLMENT,
        CustomerTimeline::PLAN_CHANGE,
        CustomerTimeline::ENROLLMENT,
    ]);
});
