<?php

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\CardStatusLog;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Invoice;
use App\Models\Payment;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

test('the download holds the five sections with the holder records and nothing from another holder', function () {
    $user = actingAsRole('financeiro');
    $customer = Customer::factory()->create(['name' => 'Maria Conceição', 'cpf' => '12345678909']);
    Card::factory()->active()->for($customer)->create(['number' => '000123']);
    $old = Card::factory()->lost()->for($customer)->create(['number' => '000087']);
    CardStatusLog::factory()->for($old)->transition(CardStatus::Active, CardStatus::Lost)->create(['reason' => 'Perda']);
    Dependent::factory()->for($customer)->create(['name' => 'Lívia Ativa', 'sequence' => 1]);
    Dependent::factory()->inactive()->for($customer)->create(['name' => 'Pedro Inativo', 'sequence' => 2]);
    $paidInvoice = Invoice::factory()->for($customer)->forMonth('2026-09')->paid()->create();
    Invoice::factory()->for($customer)->forMonth('2026-10')->open()->create();
    Payment::factory()->for($paidInvoice)->create();
    Payment::factory()->refunded()->for(Invoice::factory()->for($customer)->forMonth('2026-08')->create())->create();

    $other = Customer::factory()->create(['name' => 'José Outro', 'cpf' => '52998224725']);
    Card::factory()->active()->for($other)->create(['number' => '999999']);
    Dependent::factory()->for($other)->create(['name' => 'Dependente Outro']);
    Payment::factory()->for(Invoice::factory()->for($other)->forMonth('2026-07')->create())->create();

    $component = Livewire::test('pages::customers.show', ['customer' => $customer])
        ->call('export')
        ->assertFileDownloaded('titular-'.$customer->id.'-'.today()->toDateString().'.json');

    $json = base64_decode($component->effects['download']['content']);
    $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($data)->toHaveKeys(['titular', 'dependentes', 'cartoes', 'mensalidades', 'pagamentos'])
        ->and($data['titular']['nome'])->toBe('Maria Conceição')
        ->and($data['titular']['cpf'])->toBe('12345678909')
        ->and(array_column($data['dependentes'], 'nome'))->toBe(['Lívia Ativa', 'Pedro Inativo'])
        ->and(array_column($data['cartoes'], 'numero'))->toEqualCanonicalizing(['000123', '000087'])
        ->and(collect($data['cartoes'])->firstWhere('numero', '000087')['historico'][0])->toMatchArray(['para' => 'Extraviado', 'motivo' => 'Perda'])
        ->and(array_column($data['mensalidades'], 'referencia'))->toBe(['2026-08', '2026-09', '2026-10'])
        ->and($data['pagamentos'])->toHaveCount(2)
        ->and(array_column($data['pagamentos'], 'estornado'))->toEqualCanonicalizing([true, false])
        ->and($json)->not->toContain('José Outro', '52998224725', '999999', 'Dependente Outro', '2026-07');
});

test('atendente gets 403 when exporting', function () {
    $customer = Customer::factory()->withCard()->create();
    actingAsRole('financeiro');
    $page = Livewire::test('pages::customers.show', ['customer' => $customer]);

    actingAsRole('atendente');
    $page->call('export')->assertForbidden();

    expect(Activity::query()->where('event', 'exported')->exists())->toBeFalse();
});

test('the activity log records the export with the user and the holder', function () {
    $user = actingAsRole('admin');
    $customer = Customer::factory()->withCard()->create();

    Livewire::test('pages::customers.show', ['customer' => $customer])->call('export');

    $activity = Activity::query()->where('event', 'exported')->sole();

    expect($activity->log_name)->toBe('customers')
        ->and($activity->subject->is($customer))->toBeTrue()
        ->and($activity->causer->is($user))->toBeTrue()
        ->and($activity->description)->toBe('Exportação dos dados do titular (LGPD)');
});
