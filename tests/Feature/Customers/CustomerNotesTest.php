<?php

use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\User;
use Livewire\Livewire;

test('a blank note is rejected', function (string $note) {
    actingAsRole('financeiro');
    $customer = Customer::factory()->withCard()->create();

    Livewire::test('pages::customers.show', ['customer' => $customer])
        ->set('note', $note)
        ->call('addNote')
        ->assertHasErrors('note');

    expect(CustomerNote::count())->toBe(0);
})->with(['empty' => '', 'only spaces' => '    ']);

test('the note is saved with its author and shows first in the list and in the timeline', function () {
    $user = actingAsRole('financeiro');
    $user->update(['name' => 'Paulo Mendes']);
    $customer = Customer::factory()->withCard()->create();
    CustomerNote::factory()->for($customer)->for(User::factory()->create())->create(['body' => 'Nota antiga', 'created_at' => now()->subDays(3)]);

    Livewire::withQueryParams(['tab' => 'observacoes'])
        ->test('pages::customers.show', ['customer' => $customer])
        ->set('note', '  Prefere contato por WhatsApp à tarde.  ')
        ->call('addNote')
        ->assertHasNoErrors()
        ->assertSet('note', '')
        ->assertSeeHtmlInOrder(['Prefere contato por WhatsApp à tarde.', 'Financeiro · Paulo Mendes · '.today()->format('d/m/Y'), 'Nota antiga'])
        ->set('tab', 'historico')
        ->assertSeeHtml('Prefere contato por WhatsApp à tarde.');

    $note = CustomerNote::query()->latest('id')->first();

    expect($note->body)->toBe('Prefere contato por WhatsApp à tarde.')
        ->and($note->user_id)->toBe($user->id)
        ->and($note->customer_id)->toBe($customer->id);
});

test('atendente cannot call addNote', function () {
    $customer = Customer::factory()->withCard()->create();
    actingAsRole('financeiro');
    $page = Livewire::test('pages::customers.show', ['customer' => $customer]);

    actingAsRole('atendente');
    $page->set('note', 'Tentativa')->call('addNote')->assertForbidden();

    expect(CustomerNote::count())->toBe(0);
});
