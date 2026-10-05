<?php

namespace App\Support;

use App\Queries\DelinquentsQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Sidebar definition and route metadata (crumb + title) for the app layout.
 *
 * @phpstan-type NavItem array{route: string, label: string, icon: string, permission: string}
 * @phpstan-type NavGroup array{title: string, items: list<NavItem>}
 */
class Navigation
{
    /**
     * @return list<NavGroup>
     */
    public static function groups(): array
    {
        return [
            ['title' => 'Atendimento', 'items' => [
                ['route' => 'dashboard', 'label' => 'Painel', 'icon' => 'layout-dashboard', 'permission' => 'dashboard.view'],
                ['route' => 'quick-search', 'label' => 'Consulta rápida', 'icon' => 'scan-line', 'permission' => 'cards.check'],
                ['route' => 'sales.create', 'label' => 'Nova venda', 'icon' => 'user-plus', 'permission' => 'sales.create'],
                ['route' => 'customers.index', 'label' => 'Clientes', 'icon' => 'users', 'permission' => 'customers.view'],
                ['route' => 'plans.index', 'label' => 'Planos', 'icon' => 'badge-check', 'permission' => 'plans.manage'],
                ['route' => 'cards.stock', 'label' => 'Estoque de cartões', 'icon' => 'credit-card', 'permission' => 'cards.stock.view'],
            ]],
            ['title' => 'Faturamento', 'items' => [
                ['route' => 'billing.index', 'label' => 'Mensalidades', 'icon' => 'receipt', 'permission' => 'billing.view'],
                ['route' => 'billing.delinquents', 'label' => 'Inadimplentes', 'icon' => 'circle-alert', 'permission' => 'billing.view'],
            ]],
            ['title' => 'Financeiro', 'items' => [
                ['route' => 'finance.entries', 'label' => 'Lançamentos', 'icon' => 'arrow-left-right', 'permission' => 'finance.view'],
                ['route' => 'finance.payables', 'label' => 'Contas a pagar', 'icon' => 'arrow-up-right', 'permission' => 'finance.view'],
                ['route' => 'finance.receivables', 'label' => 'Contas a receber', 'icon' => 'arrow-down-left', 'permission' => 'finance.view'],
                ['route' => 'finance.cash-flow', 'label' => 'Fluxo de caixa', 'icon' => 'chart-column', 'permission' => 'finance.view'],
                ['route' => 'finance.categories', 'label' => 'Categorias', 'icon' => 'tags', 'permission' => 'finance.view'],
            ]],
            ['title' => 'Sistema', 'items' => [
                ['route' => 'reports.index', 'label' => 'Relatórios', 'icon' => 'file-text', 'permission' => 'reports.view'],
                ['route' => 'settings', 'label' => 'Configurações', 'icon' => 'settings', 'permission' => 'settings.manage'],
                ['route' => 'users.index', 'label' => 'Usuários', 'icon' => 'user-cog', 'permission' => 'users.manage'],
            ]],
        ];
    }

    /**
     * Groups filtered to the items the current user may see; empty groups are dropped.
     *
     * @return list<NavGroup>
     */
    public static function visibleGroups(): array
    {
        return array_values(collect(self::groups())
            ->map(fn (array $group): array => [
                ...$group,
                'items' => array_values(array_filter($group['items'], fn (array $item): bool => self::allows($item['permission']))),
            ])
            ->filter(fn (array $group): bool => $group['items'] !== [])
            ->all());
    }

    /**
     * Whether the current user holds a permission (guests hold none).
     */
    public static function allows(string $permission): bool
    {
        return Gate::allows($permission);
    }

    /**
     * Counter shown next to a nav item, or null when there is none (or it is
     * zero). Only called for visible items, so the query never runs for users
     * without the item's permission.
     */
    public static function badge(string $route): ?int
    {
        $count = match ($route) {
            'billing.delinquents' => app(DelinquentsQuery::class)->holdersCount(),
            default => 0,
        };

        return $count > 0 ? $count : null;
    }

    /**
     * The nav item that owns the current route (the customer sheet and edit screen belong to "Clientes").
     *
     * @return array{group: string, item: NavItem}|null
     */
    public static function current(): ?array
    {
        $routeName = Route::currentRouteName();
        $routeName = in_array($routeName, ['customers.show', 'customers.edit'], true) ? 'customers.index' : $routeName;

        foreach (self::groups() as $group) {
            foreach ($group['items'] as $item) {
                if ($item['route'] === $routeName) {
                    return ['group' => $group['title'], 'item' => $item];
                }
            }
        }

        return null;
    }
}
