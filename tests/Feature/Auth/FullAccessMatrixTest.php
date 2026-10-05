<?php

use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Every application route (spec §8) with the profiles allowed in (§10.1).
 * `{customer}` and `{payment}` are replaced by persisted records.
 *
 * @return array<string, array{string, list<string>}>
 */
function fullAccessMatrix(): array
{
    $all = ['admin', 'financeiro', 'atendente'];
    $staff = ['admin', 'financeiro'];

    return [
        'dashboard' => ['/painel', $staff],
        'quick-search' => ['/consulta', $all],
        'sales.create' => ['/vendas/nova', $all],
        'customers.index' => ['/clientes', $staff],
        'customers.show' => ['/clientes/{customer}', $staff],
        'customers.edit' => ['/clientes/{customer}/editar', $staff],
        'plans.index' => ['/planos', ['admin']],
        'cards.stock' => ['/cartoes', $staff],
        'billing.index' => ['/faturamento', $staff],
        'billing.delinquents' => ['/faturamento/inadimplentes', $staff],
        'billing.delinquents.pdf' => ['/faturamento/inadimplentes/pdf', $staff],
        'billing.delinquents.excel' => ['/faturamento/inadimplentes/excel', $staff],
        'receipts.show' => ['/recibos/{payment}', $staff],
        'finance.entries' => ['/financeiro/lancamentos', $staff],
        'finance.payables' => ['/financeiro/contas-a-pagar', $staff],
        'finance.receivables' => ['/financeiro/contas-a-receber', $staff],
        'finance.cash-flow' => ['/financeiro/fluxo-de-caixa', $staff],
        'finance.categories' => ['/financeiro/categorias', $staff],
        'reports.index' => ['/relatorios', $staff],
        'settings' => ['/configuracoes', ['admin']],
        'users.index' => ['/usuarios', ['admin']],
    ];
}

/**
 * Named routes registered by `routes/web.php` behind the `auth` middleware.
 *
 * @return list<RoutingRoute>
 */
function authenticatedAppRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('auth', $route->gatherMiddleware(), true))
        ->filter(fn (RoutingRoute $route): bool => $route->getName() !== null && ! str_starts_with($route->uri(), 'livewire'))
        ->values()
        ->all();
}

beforeEach(function () {
    $this->withoutVite();
});

test('the matrix lists every authenticated route of the application', function () {
    $registered = collect(authenticatedAppRoutes())
        ->map(fn (RoutingRoute $route): string => $route->getName())
        ->reject(fn (string $name): bool => $name === 'home')
        ->sort()
        ->values()
        ->all();

    expect($registered)->toBe(collect(fullAccessMatrix())->keys()->sort()->values()->all());
});

test('every authenticated route except home is gated by a can: middleware', function () {
    foreach (authenticatedAppRoutes() as $route) {
        if ($route->getName() === 'home') {
            continue;
        }

        expect(collect($route->gatherMiddleware())->contains(fn (string $middleware): bool => str_starts_with($middleware, 'can:')))
            ->toBeTrue("Route [{$route->getName()}] has no can: middleware");
    }
});

test('each profile reaches only the routes of its permissions', function (string $role, string $uri, bool $allowed) {
    actingAsRole($role);

    $uri = strtr($uri, [
        '{customer}' => str_contains($uri, '{customer}') ? (string) Customer::factory()->create()->id : '',
        '{payment}' => str_contains($uri, '{payment}') ? (string) Payment::factory()->create()->id : '',
    ]);

    $response = $this->get($uri);

    if ($allowed) {
        $response->assertOk();
    } else {
        $response->assertForbidden();
    }
})->with(function (): array {
    $cases = [];

    foreach (fullAccessMatrix() as $name => [$uri, $allowedRoles]) {
        foreach (['admin', 'financeiro', 'atendente'] as $role) {
            $cases["{$role} · {$name}"] = [$role, $uri, in_array($role, $allowedRoles, true)];
        }
    }

    return $cases;
});

test('the home page sends each profile to its start page', function (string $role, string $destination) {
    actingAsRole($role);

    $this->get('/')->assertRedirect(route($destination));
})->with([
    'admin' => ['admin', 'dashboard'],
    'financeiro' => ['financeiro', 'dashboard'],
    'atendente' => ['atendente', 'quick-search'],
]);

test('visitors are redirected to the login page on every route', function (string $uri) {
    $this->get(str_replace(['{customer}', '{payment}'], '1', $uri))->assertRedirect(route('login'));
})->with(fn (): array => ['home' => '/'] + array_map(fn (array $route): string => $route[0], fullAccessMatrix()));
