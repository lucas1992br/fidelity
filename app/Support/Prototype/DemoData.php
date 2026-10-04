<?php

namespace App\Support\Prototype;

use App\Enums\CardStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Kinship;
use App\Enums\PaymentMethod;
use App\Support\Format;
use Illuminate\Support\Collection;

/**
 * Static data ported from the "Cartao Fidelidade" design prototype.
 *
 * Temporary: backs the visual foundation until the domain models exist.
 * Every consumer should be migrated to Eloquent and then this class deleted.
 *
 * @phpstan-type Dependent array{name: string, kinship: Kinship, birth: string}
 * @phpstan-type Customer array{id: int, name: string, cpf: string, card: string, old: list<array{number: string, note: string}>, plan: string, status: CardStatus, phone: string, email: string, birth: string, address: string, due: int, joined: string, assigned: string, deps: list<Dependent>, notes: list<array{body: string, meta: string}>}
 * @phpstan-type Invoice array{id: int, cid: int, ref: string, due: string, amount: float, status: InvoiceStatus, days: int}
 */
class DemoData
{
    public const TODAY = '04/10/2026';

    public const TOTAL_CUSTOMERS = 447;

    public const MIN_CARD_STOCK = 50;

    public const SUSPEND_AFTER_DAYS = 30;

    /**
     * @return array<string, array{price: float, max: int, subs: int}>
     */
    public static function plans(): array
    {
        return [
            'Individual' => ['price' => 49.9, 'max' => 0, 'subs' => 238],
            'Familiar' => ['price' => 89.9, 'max' => 4, 'subs' => 171],
            'Familiar Plus' => ['price' => 129.9, 'max' => 7, 'subs' => 38],
        ];
    }

    /**
     * @return Collection<int, Customer>
     */
    public static function customers(): Collection
    {
        return collect([
            ['id' => 1, 'name' => 'Maria Aparecida Souza', 'cpf' => '12345678909', 'card' => '000123', 'old' => [['number' => '000087', 'note' => 'Extraviado em 03/06/2025']], 'plan' => 'Familiar', 'status' => CardStatus::Active, 'phone' => '(11) 98765-4321', 'email' => 'maria.souza@email.com', 'birth' => '22/07/1979', 'address' => 'Rua das Acácias, 245 · Vila Mariana · São Paulo/SP', 'due' => 10, 'joined' => '14/02/2024', 'assigned' => '03/06/2025', 'deps' => [
                ['name' => 'Carlos Henrique Souza', 'kinship' => Kinship::Spouse, 'birth' => '03/05/1977'],
                ['name' => 'Lívia Souza', 'kinship' => Kinship::Child, 'birth' => '18/09/2012'],
                ['name' => 'Pedro Souza', 'kinship' => Kinship::Child, 'birth' => '02/01/2016'],
            ], 'notes' => [['body' => 'Prefere contato por WhatsApp no período da tarde.', 'meta' => 'Financeiro · Paulo · 12/03/2026']]],
            ['id' => 2, 'name' => 'José Ribeiro dos Santos', 'cpf' => '98765432100', 'card' => '000124', 'old' => [], 'plan' => 'Individual', 'status' => CardStatus::Suspended, 'phone' => '(11) 99654-1122', 'email' => '', 'birth' => '10/11/1958', 'address' => 'Av. Brasil, 1020 · Centro · Guarulhos/SP', 'due' => 10, 'joined' => '20/02/2024', 'assigned' => '20/02/2024', 'deps' => [], 'notes' => []],
            ['id' => 3, 'name' => 'Ana Clara Ferreira', 'cpf' => '32165498711', 'card' => '000131', 'old' => [], 'plan' => 'Familiar', 'status' => CardStatus::Active, 'phone' => '(11) 97412-3344', 'email' => 'anaclara@email.com', 'birth' => '05/03/1990', 'address' => 'Rua Tupi, 88 · Santana · São Paulo/SP', 'due' => 1, 'joined' => '08/04/2024', 'assigned' => '08/04/2024', 'deps' => [
                ['name' => 'Rafael Ferreira', 'kinship' => Kinship::Spouse, 'birth' => '14/08/1988'],
                ['name' => 'Sofia Ferreira', 'kinship' => Kinship::Child, 'birth' => '30/06/2019'],
            ], 'notes' => []],
            ['id' => 4, 'name' => 'Roberto Lima Carvalho', 'cpf' => '45678912322', 'card' => '000140', 'old' => [], 'plan' => 'Familiar Plus', 'status' => CardStatus::Active, 'phone' => '(11) 98123-7788', 'email' => 'roberto.lc@email.com', 'birth' => '19/01/1972', 'address' => 'Rua Bahia, 412 · Higienópolis · São Paulo/SP', 'due' => 5, 'joined' => '11/05/2024', 'assigned' => '11/05/2024', 'deps' => [
                ['name' => 'Helena Carvalho', 'kinship' => Kinship::Spouse, 'birth' => '02/02/1974'],
                ['name' => 'Lucas Carvalho', 'kinship' => Kinship::Child, 'birth' => '21/10/2003'],
                ['name' => 'Marina Carvalho', 'kinship' => Kinship::Child, 'birth' => '07/07/2008'],
                ['name' => 'Dona Célia Lima', 'kinship' => Kinship::Mother, 'birth' => '15/03/1948'],
                ['name' => 'Seu Jorge Lima', 'kinship' => Kinship::Father, 'birth' => '28/12/1946'],
            ], 'notes' => []],
            ['id' => 5, 'name' => 'Francisca das Chagas Oliveira', 'cpf' => '15975348633', 'card' => '000152', 'old' => [], 'plan' => 'Individual', 'status' => CardStatus::Suspended, 'phone' => '(11) 96321-4455', 'email' => '', 'birth' => '01/09/1965', 'address' => 'Rua São Jorge, 17 · Itaquera · São Paulo/SP', 'due' => 5, 'joined' => '02/07/2024', 'assigned' => '02/07/2024', 'deps' => [], 'notes' => []],
            ['id' => 6, 'name' => 'Antônio Marcos Pereira', 'cpf' => '75395185244', 'card' => '000158', 'old' => [], 'plan' => 'Familiar', 'status' => CardStatus::Active, 'phone' => '(11) 99877-6655', 'email' => 'amarcos@email.com', 'birth' => '12/12/1983', 'address' => 'Rua Pará, 300 · Mooca · São Paulo/SP', 'due' => 20, 'joined' => '15/08/2024', 'assigned' => '15/08/2024', 'deps' => [
                ['name' => 'Beatriz Pereira', 'kinship' => Kinship::Child, 'birth' => '09/04/2011'],
            ], 'notes' => []],
            ['id' => 7, 'name' => 'Juliana Mendes Rocha', 'cpf' => '85245695155', 'card' => '000163', 'old' => [], 'plan' => 'Individual', 'status' => CardStatus::Cancelled, 'phone' => '(11) 95544-3322', 'email' => 'ju.rocha@email.com', 'birth' => '25/05/1995', 'address' => 'Rua Augusta, 1500 · Consolação · São Paulo/SP', 'due' => 15, 'joined' => '03/09/2024', 'assigned' => '03/09/2024', 'deps' => [], 'notes' => []],
            ['id' => 8, 'name' => 'Sebastião Alves Costa', 'cpf' => '36925814766', 'card' => '000171', 'old' => [], 'plan' => 'Familiar', 'status' => CardStatus::Active, 'phone' => '(11) 98800-1199', 'email' => '', 'birth' => '30/04/1960', 'address' => 'Rua Piauí, 55 · Penha · São Paulo/SP', 'due' => 15, 'joined' => '21/01/2025', 'assigned' => '21/01/2025', 'deps' => [
                ['name' => 'Rosa Alves Costa', 'kinship' => Kinship::Spouse, 'birth' => '11/11/1962'],
            ], 'notes' => []],
        ]);
    }

    /**
     * @return Customer|null
     */
    public static function customer(int $id): ?array
    {
        return self::customers()->firstWhere('id', $id);
    }

    /**
     * @return Collection<int, Invoice>
     */
    public static function invoices(): Collection
    {
        return collect([
            [101, 1, '10/2026', '10/10/2026', 89.9, InvoiceStatus::Open, 0],
            [102, 2, '08/2026', '10/08/2026', 49.9, InvoiceStatus::Overdue, 55],
            [103, 2, '09/2026', '10/09/2026', 49.9, InvoiceStatus::Overdue, 24],
            [104, 3, '10/2026', '01/10/2026', 89.9, InvoiceStatus::Paid, 0],
            [105, 4, '10/2026', '05/10/2026', 129.9, InvoiceStatus::Paid, 0],
            [106, 5, '08/2026', '05/08/2026', 49.9, InvoiceStatus::Overdue, 60],
            [107, 5, '09/2026', '05/09/2026', 49.9, InvoiceStatus::Overdue, 29],
            [108, 6, '09/2026', '20/09/2026', 89.9, InvoiceStatus::Overdue, 14],
            [109, 8, '10/2026', '15/10/2026', 89.9, InvoiceStatus::Open, 0],
            [110, 2, '10/2026', '10/10/2026', 49.9, InvoiceStatus::Open, 0],
            [111, 6, '10/2026', '20/10/2026', 89.9, InvoiceStatus::Open, 0],
            [112, 1, '09/2026', '10/09/2026', 89.9, InvoiceStatus::Paid, 0],
            [113, 1, '08/2026', '10/08/2026', 89.9, InvoiceStatus::Paid, 0],
        ])->map(fn (array $row): array => array_combine(['id', 'cid', 'ref', 'due', 'amount', 'status', 'days'], $row));
    }

    /**
     * @return Invoice|null
     */
    public static function invoice(int $id): ?array
    {
        return self::invoices()->firstWhere('id', $id);
    }

    /**
     * Invoices of a customer, newest reference first.
     *
     * @return Collection<int, Invoice>
     */
    public static function invoicesFor(int $customerId): Collection
    {
        return self::invoices()
            ->where('cid', $customerId)
            ->sortByDesc(fn (array $invoice): string => implode('', array_reverse(explode('/', $invoice['ref']))))
            ->values();
    }

    /**
     * The most overdue unpaid invoice of a customer, if any.
     *
     * @return Invoice|null
     */
    public static function firstPayable(int $customerId): ?array
    {
        return self::invoices()
            ->where('cid', $customerId)
            ->filter(fn (array $invoice): bool => $invoice['status']->isPayable())
            ->sortByDesc('days')
            ->first();
    }

    /**
     * Dependents with their computed identification ("000123-02").
     *
     * @param  Customer  $customer
     * @return list<array{code: string, name: string, kinship: Kinship, birth: string}>
     */
    public static function dependentsOf(array $customer): array
    {
        return collect($customer['deps'])
            ->values()
            ->map(fn (array $dependent, int $index): array => [
                'code' => $customer['card'].'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                ...$dependent,
            ])
            ->all();
    }

    /**
     * Whether a payment would reactivate the customer's suspended card.
     */
    public static function paymentReactivates(int $invoiceId): bool
    {
        $invoice = self::invoice($invoiceId);
        $customer = $invoice ? self::customer($invoice['cid']) : null;

        if (! $invoice || ! $customer || $customer['status'] !== CardStatus::Suspended) {
            return false;
        }

        return ! self::invoices()
            ->where('cid', $customer['id'])
            ->where('id', '!=', $invoiceId)
            ->contains(fn (array $other): bool => $other['status'] === InvoiceStatus::Overdue && $other['days'] >= self::SUSPEND_AFTER_DAYS);
    }

    /**
     * Quick search by name, CPF, current card, previous card or dependent code.
     *
     * @return list<array{customer: Customer, dependent: array{code: string, name: string, kinship: Kinship, birth: string}|null, replacedCard: string|null}>
     */
    public static function search(string $query, int $limit = 3): array
    {
        $query = mb_strtolower(trim($query));
        $digits = preg_replace('/\D/', '', $query);

        if ($query === '') {
            return [];
        }

        return self::customers()
            ->map(function (array $customer) use ($query, $digits): ?array {
                $dependent = collect(self::dependentsOf($customer))->firstWhere('code', $query);
                $replaced = collect($customer['old'])->firstWhere('number', $query);
                $matchesCpf = strlen($digits) >= 3 && ! preg_match('/^\d{6}$/', $query) && str_contains($customer['cpf'], $digits);

                $hit = $dependent
                    || $replaced
                    || $customer['card'] === $query
                    || str_contains(mb_strtolower($customer['name']), $query)
                    || $matchesCpf;

                return $hit ? [
                    'customer' => $customer,
                    'dependent' => $dependent,
                    'replacedCard' => $replaced['number'] ?? null,
                ] : null;
            })
            ->filter()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Customers with overdue invoices, most overdue first.
     *
     * @return Collection<int, array{customer: Customer, count: int, total: float, maxDays: int}>
     */
    public static function delinquents(): Collection
    {
        return self::invoices()
            ->where('status', InvoiceStatus::Overdue)
            ->groupBy('cid')
            ->map(fn (Collection $overdue, int $customerId): array => [
                'customer' => self::customer($customerId),
                'count' => $overdue->count(),
                'total' => $overdue->sum('amount'),
                'maxDays' => $overdue->max('days'),
            ])
            ->sortByDesc('maxDays')
            ->values();
    }

    /**
     * Result of checking a card number before linking it.
     *
     * @return array{tone: string, label: string, available: bool}|null
     */
    public static function cardCheck(string $number, bool $revealOwner = true): ?array
    {
        $number = trim($number);

        if ($number === '') {
            return null;
        }

        $card = collect(self::stock())->firstWhere('number', $number);

        if ($card && $card['status'] === CardStatus::InStock) {
            return ['tone' => 'green', 'label' => 'Disponível no estoque', 'available' => true];
        }

        $owner = self::customers()->firstWhere('card', $number);

        if ($owner) {
            return ['tone' => 'red', 'label' => $revealOwner ? "Já vinculado a {$owner['name']}" : 'Já vinculado', 'available' => false];
        }

        if ($card) {
            return ['tone' => 'gray', 'label' => 'Cartão '.mb_strtolower($card['status']->label()).'. Não pode ser usado.', 'available' => false];
        }

        if (strlen($number) >= 6) {
            return ['tone' => 'amber', 'label' => 'Não cadastrado no estoque', 'available' => false];
        }

        return null;
    }

    /**
     * @return list<array{number: string, batch: string, status: CardStatus, customer: string, date: string}>
     */
    public static function stock(): array
    {
        return [
            ['number' => '000180', 'batch' => 'Lote gráfica set/2026', 'status' => CardStatus::InStock, 'customer' => '—', 'date' => 'Recebido 12/09/2026'],
            ['number' => '000181', 'batch' => 'Lote gráfica set/2026', 'status' => CardStatus::InStock, 'customer' => '—', 'date' => 'Recebido 12/09/2026'],
            ['number' => '000182', 'batch' => 'Lote gráfica set/2026', 'status' => CardStatus::InStock, 'customer' => '—', 'date' => 'Recebido 12/09/2026'],
            ['number' => '000179', 'batch' => 'Lote gráfica set/2026', 'status' => CardStatus::Discarded, 'customer' => '—', 'date' => 'Inutilizado 14/09/2026'],
            ['number' => '000171', 'batch' => 'Lote gráfica jan/2025', 'status' => CardStatus::Active, 'customer' => 'Sebastião Alves Costa', 'date' => 'Vinculado 21/01/2025'],
            ['number' => '000163', 'batch' => 'Lote gráfica jan/2025', 'status' => CardStatus::Cancelled, 'customer' => 'Juliana Mendes Rocha', 'date' => 'Vinculado 03/09/2024'],
            ['number' => '000158', 'batch' => 'Lote gráfica jan/2025', 'status' => CardStatus::Active, 'customer' => 'Antônio Marcos Pereira', 'date' => 'Vinculado 15/08/2024'],
            ['number' => '000152', 'batch' => 'Lote gráfica fev/2024', 'status' => CardStatus::Suspended, 'customer' => 'Francisca das Chagas Oliveira', 'date' => 'Vinculado 02/07/2024'],
            ['number' => '000124', 'batch' => 'Lote gráfica fev/2024', 'status' => CardStatus::Suspended, 'customer' => 'José Ribeiro dos Santos', 'date' => 'Vinculado 20/02/2024'],
            ['number' => '000087', 'batch' => 'Lote gráfica fev/2024', 'status' => CardStatus::Lost, 'customer' => 'Maria Aparecida Souza', 'date' => 'Extraviado 03/06/2025'],
        ];
    }

    /**
     * @return list<array{description: string, meta: string, range: string, percent: int, usage: string}>
     */
    public static function batches(): array
    {
        return [
            ['description' => 'Lote gráfica set/2026', 'meta' => 'Gráfica Impressul · recebido 12/09/2026', 'range' => '000172 – 000221', 'percent' => 24, 'usage' => '12 vinculados · 37 disponíveis · 1 inutilizado'],
            ['description' => 'Lote gráfica jan/2025', 'meta' => 'Gráfica Impressul · recebido 08/01/2025', 'range' => '000101 – 000171', 'percent' => 100, 'usage' => '71 de 71 usados'],
            ['description' => 'Lote gráfica fev/2024', 'meta' => 'Gráfica Central · recebido 10/02/2024', 'range' => '000001 – 000100', 'percent' => 100, 'usage' => '100 de 100 usados'],
        ];
    }

    /**
     * Dashboard indicators (spec §6.4).
     *
     * @return list<array{label: string, value: string, note: string, icon: string, tone: string, alert?: bool}>
     */
    public static function kpis(): array
    {
        return [
            ['label' => 'Cartões ativos', 'value' => '412', 'note' => '+9 neste mês', 'icon' => 'credit-card', 'tone' => 'blue'],
            ['label' => 'Cartões em estoque', 'value' => '38', 'note' => 'Abaixo do mínimo de 50', 'icon' => 'package', 'tone' => 'amber', 'alert' => true],
            ['label' => 'Vidas atendidas', 'value' => '1.096', 'note' => 'Titulares + dependentes ativos', 'icon' => 'heart-handshake', 'tone' => 'blue'],
            ['label' => 'Inadimplência', 'value' => '7,8%', 'note' => '35 de 447 titulares', 'icon' => 'trending-down', 'tone' => 'red'],
            ['label' => 'Receita do mês', 'value' => 'R$ 38.420,50', 'note' => 'Outubro · regime de caixa', 'icon' => 'arrow-down-left', 'tone' => 'green'],
            ['label' => 'Despesa do mês', 'value' => 'R$ 21.180,00', 'note' => 'Outubro · regime de caixa', 'icon' => 'arrow-up-right', 'tone' => 'gray'],
            ['label' => 'Valor em aberto', 'value' => 'R$ 6.565,10', 'note' => 'Em aberto + em atraso', 'icon' => 'clock', 'tone' => 'blue'],
            ['label' => 'Valor em atraso', 'value' => 'R$ 2.824,10', 'note' => 'Faturas vencidas', 'icon' => 'circle-alert', 'tone' => 'red'],
        ];
    }

    /**
     * Income x expense of the last 12 months, in thousands of reais.
     *
     * @return list<array{month: string, income: float, expense: float}>
     */
    public static function chart(): array
    {
        return collect([
            ['nov', 31.2, 19.8], ['dez', 32.0, 24.1], ['jan', 32.9, 20.4], ['fev', 33.4, 19.9],
            ['mar', 34.1, 21.6], ['abr', 34.8, 20.2], ['mai', 35.5, 22.9], ['jun', 36.0, 20.7],
            ['jul', 36.4, 21.3], ['ago', 37.2, 23.4], ['set', 37.9, 20.8], ['out', 38.4, 21.2],
        ])->map(fn (array $row): array => array_combine(['month', 'income', 'expense'], $row))->all();
    }

    /**
     * @return list<array{receipt: int, name: string, ref: string, method: PaymentMethod, date: string, amount: float}>
     */
    public static function lastPayments(): array
    {
        return [
            ['receipt' => 1042, 'name' => 'Roberto Lima Carvalho', 'ref' => '10/2026', 'method' => PaymentMethod::Pix, 'date' => '02/10/2026', 'amount' => 129.9],
            ['receipt' => 1041, 'name' => 'Ana Clara Ferreira', 'ref' => '10/2026', 'method' => PaymentMethod::Card, 'date' => '30/09/2026', 'amount' => 89.9],
            ['receipt' => 1040, 'name' => 'Neide Batista Ramos', 'ref' => '09/2026', 'method' => PaymentMethod::Cash, 'date' => '29/09/2026', 'amount' => 49.9],
            ['receipt' => 1039, 'name' => 'Cláudio Moura Lins', 'ref' => '09/2026', 'method' => PaymentMethod::Pix, 'date' => '28/09/2026', 'amount' => 89.9],
            ['receipt' => 1038, 'name' => 'Teresa Gomes Vidal', 'ref' => '09/2026', 'method' => PaymentMethod::Boleto, 'date' => '27/09/2026', 'amount' => 129.9],
        ];
    }

    /**
     * Customer timeline (spec: CustomerTimeline service), newest first.
     *
     * @param  Customer  $customer
     * @return list<array{date: string, title: string, description: string, dot: string}>
     */
    public static function timeline(array $customer): array
    {
        $price = self::plans()[$customer['plan']]['price'];
        $firstCard = $customer['old'] ? end($customer['old'])['number'] : $customer['card'];

        return [
            ['date' => self::TODAY, 'title' => 'Mensalidade 10/2026 gerada', 'description' => Format::money($price)." · vence dia {$customer['due']}", 'dot' => 'bg-guardian-400'],
            ...($customer['status'] === CardStatus::Suspended ? [['date' => '09/09/2026', 'title' => 'Cartão suspenso', 'description' => 'Suspensão automática: 30 dias em atraso · sistema', 'dot' => 'bg-warning-500']] : []),
            ['date' => '10/09/2026', 'title' => 'Pagamento recebido', 'description' => 'Recibo nº 1018 · PIX · '.Format::money($price), 'dot' => 'bg-success-500'],
            ...array_map(fn (array $old): array => [
                'date' => substr($old['note'], -10),
                'title' => 'Segunda via do cartão',
                'description' => "{$old['number']} → {$customer['card']} · ".explode(' em', $old['note'])[0],
                'dot' => 'bg-embrace-400',
            ], $customer['old']),
            ['date' => $customer['joined'], 'title' => "Adesão ao plano {$customer['plan']}", 'description' => "Cartão {$firstCard} vinculado", 'dot' => 'bg-guardian-600'],
        ];
    }
}
