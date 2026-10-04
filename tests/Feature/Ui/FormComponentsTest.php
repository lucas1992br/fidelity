<?php

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;

test('field shows the validation message in danger instead of the hint', function () {
    $this->withViewErrors(['form.cpf' => 'CPF inválido.'])
        ->blade('<x-ui.field label="CPF" for="cpf" error="form.cpf" hint="Só números"><x-ui.input id="cpf" error="form.cpf" /></x-ui.field>')
        ->assertSee('for="cpf"', false)
        ->assertSee('CPF inválido.')
        ->assertSee('text-danger-700', false)
        ->assertSee('aria-invalid="true"', false)
        ->assertDontSee('Só números');
});

test('field shows the hint when the field has no error', function () {
    $this->withViewErrors([])
        ->blade('<x-ui.field label="CPF" error="form.cpf" hint="Só números"><x-ui.input error="form.cpf" /></x-ui.field>')
        ->assertSee('Só números')
        ->assertSee('focus:shadow-focus', false)
        ->assertDontSee('aria-invalid', false);
});

test('input applies the Alpine mask for each Brazilian format', function (string $mask, string $expected) {
    $this->withViewErrors([])
        ->blade('<x-ui.input :mask="$mask" />', ['mask' => $mask])
        ->assertSee($expected, false);
})->with([
    'cpf' => ['cpf', 'x-mask="999.999.999-99"'],
    'phone' => ['phone', '(99) 99999-9999'],
    'zip' => ['zip', 'x-mask="99999-999"'],
    'date' => ['date', 'x-mask="99/99/9999"'],
    'money' => ['money', 'x-mask:dynamic="$money($input, &#039;,&#039;, &#039;.&#039;)"'],
]);

test('select renders the placeholder and options and marks errors in danger', function () {
    $this->withViewErrors(['plan' => 'Escolha um plano.'])
        ->blade('<x-ui.select error="plan" placeholder="Selecione" :options="[1 => \'Individual\', 2 => \'Familiar\']" />')
        ->assertSeeInOrder(['<option value="">Selecione</option>', '<option value="1">Individual</option>', '<option value="2">Familiar</option>'], false)
        ->assertSee('border-danger-500', false);
});

test('textarea renders its content with the requested rows', function () {
    $this->withViewErrors([])
        ->blade('<x-ui.textarea rows="5" wire:model="note">Observação</x-ui.textarea>')
        ->assertSee('rows="5"', false)
        ->assertSee('wire:model="note"', false)
        ->assertSee('>Observação</textarea>', false);
});

test('tabs highlight the active tab with the guardian border and switch through the Livewire property', function () {
    $html = Blade::render('<x-ui.tabs :tabs="[\'data\' => \'Dados\', \'invoices\' => \'Mensalidades\']" active="invoices" model="tab" />');

    expect($html)
        ->toContain('role="tablist"')
        ->toContain("wire:click=\"\$set('tab', 'data')\"")
        ->toMatch('/aria-selected="true"[^>]*border-guardian-500 text-guardian-700[^>]*>Mensalidades/s')
        ->toMatch('/aria-selected="false"[^>]*border-transparent[^>]*>Dados/s');
});

test('progress fills the bar proportionally and clamps to 100%', function (int $value, int $max, string $width) {
    $this->blade('<x-ui.progress :value="$value" :max="$max" />', ['value' => $value, 'max' => $max])
        ->assertSee('role="progressbar"', false)
        ->assertSee("width: {$width}", false);
})->with([
    'partial' => [12, 50, '24%'],
    'over' => [80, 50, '100%'],
    'empty batch' => [0, 0, '0%'],
]);

test('callout uses the tone palette', function (string $tone, string $classes) {
    $this->blade('<x-ui.callout :tone="$tone">Aviso</x-ui.callout>', ['tone' => $tone])
        ->assertSee($classes, false)
        ->assertSee('Aviso');
})->with([
    ['success', 'bg-success-50 text-success-700'],
    ['warning', 'bg-warning-50 text-warning-700'],
    ['danger', 'bg-danger-50 text-danger-700'],
    ['info', 'bg-guardian-50 text-guardian-700'],
]);

test('empty state shows the title, description and actions', function () {
    $this->blade('<x-ui.empty-state title="Nenhum cliente" description="Cadastre a primeira venda."><x-ui.button>Nova venda</x-ui.button></x-ui.empty-state>')
        ->assertSeeInOrder(['Nenhum cliente', 'Cadastre a primeira venda.', 'Nova venda']);
});

test('pagination links use the design system view', function () {
    $paginator = new LengthAwarePaginator(range(1, 15), 45, 15, 2, ['path' => '/clientes']);

    expect($paginator->links()->toHtml())
        ->toContain('aria-label="Paginação"')
        ->toContain('Mostrando')
        ->toContain('bg-guardian-500 text-white" aria-current="page"')
        ->toContain('href="/clientes?page=3"')
        ->not->toContain('wire:click');
});

test('Livewire pagination reuses the design system view with wire:click navigation', function () {
    $paginator = new LengthAwarePaginator(range(1, 15), 45, 15, 2, ['path' => '/clientes']);

    expect(view('livewire::tailwind', ['paginator' => $paginator, 'elements' => [[1 => '/clientes?page=1', 2 => '/clientes?page=2', 3 => '/clientes?page=3']]])->render())
        ->toContain('aria-label="Paginação"')
        ->toContain("wire:click.prevent=\"gotoPage(3, 'page')\"")
        ->toContain("wire:click.prevent=\"nextPage('page')\"");
});
