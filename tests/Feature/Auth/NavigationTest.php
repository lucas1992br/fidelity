<?php

use App\Support\Navigation;

/**
 * Route names of the sidebar items the current user sees.
 *
 * @return list<string>
 */
function visibleNavRoutes(): array
{
    return collect(Navigation::visibleGroups())
        ->flatMap(fn (array $group): array => array_column($group['items'], 'route'))
        ->values()
        ->all();
}

beforeEach(function () {
    $this->withoutVite();
});

test('atendente sees only the quick search and the new sale', function () {
    actingAsRole('atendente');

    expect(visibleNavRoutes())->toBe(['quick-search', 'sales.create']);

    $this->get(route('quick-search'))
        ->assertOk()
        ->assertSee('Consulta rápida')
        ->assertSee('Nova venda')
        ->assertDontSee('href="'.route('dashboard').'"', false)
        ->assertDontSee('href="'.route('customers.index').'"', false)
        ->assertDontSee('href="'.route('billing.index').'"', false);
});

test('financeiro does not see plans, settings and users', function () {
    actingAsRole('financeiro');

    expect(visibleNavRoutes())
        ->toContain('dashboard', 'sales.create', 'customers.index', 'billing.index', 'finance.entries', 'reports.index')
        ->not->toContain('plans.index', 'settings', 'users.index');
});

test('admin sees every item', function () {
    actingAsRole('admin');

    $allRoutes = collect(Navigation::groups())
        ->flatMap(fn (array $group): array => array_column($group['items'], 'route'))
        ->all();

    expect(visibleNavRoutes())->toBe($allRoutes);
});

test('guests see no item', function () {
    expect(Navigation::visibleGroups())->toBe([]);
});

test('the header shows the user name, profile and logout button', function () {
    $user = actingAsRole('financeiro');

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder([$user->name, 'Financeiro', 'Sair']);
});
