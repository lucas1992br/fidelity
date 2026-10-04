<?php

use App\Models\Customer;
use App\Models\Payment;

/**
 * Every authenticated screen (spec §8) with the profiles allowed in (§10.1).
 * `{customer}` and `{payment}` are replaced by persisted records.
 *
 * @return array<string, array{string, list<string>}>
 */
function routeMatrix(): array
{
    $all = ['admin', 'financeiro', 'atendente'];
    $staff = ['admin', 'financeiro'];

    return [
        '/painel' => ['/painel', $staff],
        '/consulta' => ['/consulta', $all],
        '/vendas/nova' => ['/vendas/nova', $all],
        '/clientes' => ['/clientes', $staff],
        '/clientes/{customer}' => ['/clientes/{customer}', $staff],
        '/clientes/{customer}/editar' => ['/clientes/{customer}/editar', $staff],
        '/planos' => ['/planos', ['admin']],
        '/cartoes' => ['/cartoes', $staff],
        '/faturamento' => ['/faturamento', $staff],
        '/faturamento/inadimplentes' => ['/faturamento/inadimplentes', $staff],
        '/recibos/{payment}' => ['/recibos/{payment}', $staff],
        '/financeiro/lancamentos' => ['/financeiro/lancamentos', $staff],
        '/financeiro/contas-a-pagar' => ['/financeiro/contas-a-pagar', $staff],
        '/financeiro/contas-a-receber' => ['/financeiro/contas-a-receber', $staff],
        '/financeiro/fluxo-de-caixa' => ['/financeiro/fluxo-de-caixa', $staff],
        '/financeiro/categorias' => ['/financeiro/categorias', $staff],
        '/relatorios' => ['/relatorios', $staff],
        '/configuracoes' => ['/configuracoes', ['admin']],
        '/usuarios' => ['/usuarios', ['admin']],
    ];
}

beforeEach(function () {
    $this->withoutVite();
});

test('each profile reaches only the screens of its permissions', function (string $role, string $uri, bool $allowed) {
    actingAsRole($role);

    $uri = strtr($uri, [
        '{customer}' => str_contains($uri, '{customer}') ? Customer::factory()->create()->id : '',
        '{payment}' => str_contains($uri, '{payment}') ? Payment::factory()->create()->id : '',
    ]);

    $response = $this->get($uri);

    if ($allowed) {
        $response->assertOk();
    } else {
        $response->assertForbidden()->assertSee('Você não tem permissão para acessar esta página');
    }
})->with(function (): array {
    $cases = [];

    foreach (routeMatrix() as $label => [$uri, $allowedRoles]) {
        foreach (['admin', 'financeiro', 'atendente'] as $role) {
            $cases["{$role} · {$label}"] = [$role, $uri, in_array($role, $allowedRoles, true)];
        }
    }

    return $cases;
});

test('guests are redirected to the login page on every screen', function (string $uri) {
    $this->get(str_replace(['{customer}', '{payment}'], '1', $uri))->assertRedirect(route('login'));
})->with(fn (): array => array_map(fn (array $route): string => $route[0], routeMatrix()));
