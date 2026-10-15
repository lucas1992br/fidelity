<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @param  array<string, mixed>  $attributes
 */
function insertPlan(array $attributes = []): int
{
    return DB::table('plans')->insertGetId([
        'name' => 'Individual',
        'price' => 49.90,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertCustomer(array $attributes = []): int
{
    return DB::table('customers')->insertGetId([
        'plan_id' => $attributes['plan_id'] ?? insertPlan(),
        'monthly_amount' => '89.90',
        'name' => 'Maria da Conceição',
        'cpf' => fake()->unique()->numerify('###########'),
        'birth_date' => '1980-05-10',
        'phone' => '11999990000',
        'zip_code' => '01001000',
        'street' => 'Praça da Sé',
        'number' => '100',
        'district' => 'Sé',
        'city' => 'São Paulo',
        'state' => 'SP',
        'due_day' => 10,
        'joined_at' => '2026-10-01',
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertDependent(array $attributes = []): int
{
    return DB::table('dependents')->insertGetId([
        'customer_id' => $attributes['customer_id'] ?? insertCustomer(),
        'kinship_id' => DB::table('kinships')->insertGetId(['name' => 'Filho(a)', 'slug' => fake()->unique()->slug()]),
        'name' => 'João da Conceição',
        'birth_date' => '2010-02-01',
        'sequence' => 1,
        ...$attributes,
    ]);
}

function insertCardStatus(): int
{
    return DB::table('card_statuses')->insertGetId(['name' => 'Ativo', 'slug' => fake()->unique()->slug(), 'color' => 'green']);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertCard(array $attributes = []): int
{
    return DB::table('cards')->insertGetId([
        'number' => fake()->unique()->numerify('######'),
        'card_status_id' => $attributes['card_status_id'] ?? insertCardStatus(),
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertInvoice(array $attributes = []): int
{
    $planId = insertPlan();

    return DB::table('invoices')->insertGetId([
        'customer_id' => $attributes['customer_id'] ?? insertCustomer(['plan_id' => $planId]),
        'plan_id' => $planId,
        'reference_month' => '2026-10-01',
        'amount' => 49.90,
        'due_date' => '2026-10-10',
        'invoice_status_id' => DB::table('invoice_statuses')->insertGetId(['name' => 'Em aberto', 'slug' => fake()->unique()->slug(), 'color' => 'blue']),
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertPayment(array $attributes = []): int
{
    return DB::table('payments')->insertGetId([
        'invoice_id' => $attributes['invoice_id'] ?? insertInvoice(),
        'paid_at' => '2026-10-05',
        'amount' => 49.90,
        'payment_method_id' => DB::table('payment_methods')->insertGetId(['name' => 'PIX', 'slug' => fake()->unique()->slug()]),
        'user_id' => User::factory()->create()->id,
        ...$attributes,
    ]);
}

function insertEntryType(string $slug): int
{
    return DB::table('entry_types')->where('slug', $slug)->value('id')
        ?? DB::table('entry_types')->insertGetId(['name' => $slug, 'slug' => $slug]);
}

function insertFinancialCategory(string $entryTypeSlug): int
{
    return DB::table('financial_categories')->insertGetId(['entry_type_id' => insertEntryType($entryTypeSlug), 'name' => 'Aluguel']);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertFinancialEntry(array $attributes = []): int
{
    $categoryId = $attributes['financial_category_id'] ?? insertFinancialCategory('expense');

    return DB::table('financial_entries')->insertGetId([
        'entry_type_id' => DB::table('financial_categories')->where('id', $categoryId)->value('entry_type_id'),
        'financial_category_id' => $categoryId,
        'description' => 'Aluguel de outubro',
        'amount' => 2500,
        'due_date' => '2026-10-10',
        'entry_status_id' => DB::table('entry_statuses')->insertGetId(['name' => 'Pendente', 'slug' => fake()->unique()->slug(), 'color' => 'blue']),
        'user_id' => User::factory()->create()->id,
        ...$attributes,
    ]);
}

function indexDefinition(string $indexName): ?string
{
    return DB::scalar('select indexdef from pg_indexes where indexname = ?', [$indexName]);
}

test('plans default to active with no dependents', function () {
    $plan = DB::table('plans')->find(insertPlan());

    expect($plan->is_active)->toBeTrue()
        ->and($plan->max_dependents)->toBe(0)
        ->and($plan->deleted_at)->toBeNull();
});

test('plans reject negative price and dependents limit', function (array $attributes, string $constraint) {
    expect(fn () => insertPlan($attributes))->toThrow(QueryException::class, $constraint);
})->with([
    'price' => [['price' => -0.01], 'plans_price_non_negative'],
    'max_dependents' => [['max_dependents' => -1], 'plans_max_dependents_non_negative'],
]);

test('customers due day must be between 1 and 28', function (int $dueDay) {
    expect(fn () => insertCustomer(['due_day' => $dueDay]))->toThrow(QueryException::class, 'customers_due_day_range');
})->with([0, 29]);

test('customers accept due days at the range limits', function () {
    insertCustomer(['due_day' => 1]);
    insertCustomer(['due_day' => 28]);

    expect(DB::table('customers')->count())->toBe(2);
});

test('customers cpf is unique', function () {
    insertCustomer(['cpf' => '12345678909']);
    insertCustomer(['cpf' => '12345678909']);
})->throws(UniqueConstraintViolationException::class);

test('a plan with customers cannot be deleted', function () {
    $planId = insertPlan();
    insertCustomer(['plan_id' => $planId]);

    DB::table('plans')->delete($planId);
})->throws(QueryException::class, 'customers_plan_id_foreign');

test('customers name has an unaccented trigram index for partial search', function () {
    insertCustomer(['name' => 'Maria da Conceição']);

    expect(indexDefinition('customers_name_trgm'))->toContain('gin (f_unaccent((name)::text) gin_trgm_ops)')
        ->and(DB::table('customers')->whereRaw('f_unaccent(name) ilike f_unaccent(?)', ['%CONCEICAO%'])->count())->toBe(1);
});

test('dependents default to active and accept a missing cpf', function () {
    $customerId = insertCustomer();
    $dependent = DB::table('dependents')->find(insertDependent(['customer_id' => $customerId, 'sequence' => 1]));
    insertDependent(['customer_id' => $customerId, 'sequence' => 2]);

    expect($dependent->is_active)->toBeTrue()
        ->and($dependent->cpf)->toBeNull()
        ->and(DB::table('dependents')->whereNull('cpf')->count())->toBe(2);
});

test('dependents sequence is unique per customer', function () {
    $customerId = insertCustomer();
    insertDependent(['customer_id' => $customerId, 'sequence' => 1]);
    insertDependent(['customer_id' => insertCustomer(), 'sequence' => 1]);

    expect(fn () => insertDependent(['customer_id' => $customerId, 'sequence' => 1]))
        ->toThrow(UniqueConstraintViolationException::class, 'dependents_customer_id_sequence_unique');
});

test('dependents cpf is unique when filled', function () {
    insertDependent(['cpf' => '12345678909']);
    insertDependent(['cpf' => '12345678909']);
})->throws(UniqueConstraintViolationException::class);

test('deleting a customer removes its dependents', function () {
    $customerId = insertCustomer();
    insertDependent(['customer_id' => $customerId]);

    DB::table('customers')->delete($customerId);

    expect(DB::table('dependents')->count())->toBe(0);
});

test('plan changes are an append-only log without updated_at', function () {
    expect(Schema::hasColumn('plan_changes', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('plan_changes', 'updated_at'))->toBeFalse();
});

test('plan changes accept a missing origin plan on enrollment', function () {
    $planId = insertPlan();

    DB::table('plan_changes')->insert([
        'customer_id' => insertCustomer(['plan_id' => $planId]),
        'to_plan_id' => $planId,
        'to_price' => 49.90,
        'user_id' => User::factory()->create()->id,
    ]);

    expect(DB::table('plan_changes')->whereNull('from_plan_id')->whereNull('from_price')->count())->toBe(1);
});

test('customers, plans and users referenced by history cannot be deleted', function (string $table, string $constraint) {
    $planId = insertPlan();
    $customerId = insertCustomer(['plan_id' => $planId]);
    $userId = User::factory()->create()->id;

    DB::table('plan_changes')->insert([
        'customer_id' => $customerId,
        'to_plan_id' => $planId,
        'to_price' => 49.90,
        'user_id' => $userId,
    ]);
    DB::table('customer_notes')->insert(['customer_id' => $customerId, 'user_id' => $userId, 'body' => 'Ligar amanhã']);

    $ids = ['customers' => $customerId, 'users' => $userId, 'plans' => $planId];

    expect(fn () => DB::table($table)->delete($ids[$table]))->toThrow(QueryException::class, $constraint);
})->with([
    'customer' => ['customers', 'plan_changes_customer_id_foreign'],
    'user' => ['users', 'plan_changes_user_id_foreign'],
    'plan' => ['plans', 'foreign'],
]);

test('customer notes cannot outlive their customer', function () {
    $customerId = insertCustomer();
    DB::table('customer_notes')->insert(['customer_id' => $customerId, 'user_id' => User::factory()->create()->id, 'body' => 'Ligar amanhã']);

    DB::table('customers')->delete($customerId);
})->throws(QueryException::class, 'customer_notes_customer_id_foreign');

test('cards start in stock without customer, batch or current flag', function () {
    $card = DB::table('cards')->find(insertCard());

    expect($card->is_current)->toBeFalse()
        ->and($card->customer_id)->toBeNull()
        ->and($card->card_batch_id)->toBeNull()
        ->and(indexDefinition('cards_card_status_id_index'))->not->toBeNull();
});

test('cards number is unique', function () {
    insertCard(['number' => '000123']);
    insertCard(['number' => '000123']);
})->throws(UniqueConstraintViolationException::class);

test('a customer has at most one current card', function () {
    $customerId = insertCustomer();
    insertCard(['customer_id' => $customerId, 'is_current' => true]);

    expect(fn () => insertCard(['customer_id' => $customerId, 'is_current' => true]))
        ->toThrow(UniqueConstraintViolationException::class, 'cards_one_current_per_customer');
});

test('a customer keeps any number of past cards next to the current one', function () {
    $customerId = insertCustomer();
    insertCard(['customer_id' => $customerId, 'is_current' => false]);
    insertCard(['customer_id' => $customerId, 'is_current' => false]);
    insertCard(['customer_id' => $customerId, 'is_current' => true]);

    expect(DB::table('cards')->where('customer_id', $customerId)->count())->toBe(3)
        ->and(indexDefinition('cards_one_current_per_customer'))->toContain('WHERE is_current');
});

test('card batches record quantity and the informative number range', function () {
    $batchId = DB::table('card_batches')->insertGetId([
        'description' => 'Lote gráfica out/2026',
        'received_at' => '2026-10-01',
        'quantity' => 500,
        'first_number' => '000001',
        'last_number' => '000500',
        'user_id' => User::factory()->create()->id,
    ]);
    insertCard(['card_batch_id' => $batchId]);

    expect(DB::table('cards')->where('card_batch_id', $batchId)->count())->toBe(1);
});

test('card status logs are append-only and accept the system as author', function () {
    $cardId = insertCard();

    DB::table('card_status_logs')->insert([
        'card_id' => $cardId,
        'to_card_status_id' => insertCardStatus(),
        'reason' => 'Suspensão automática: 32 dias em atraso',
    ]);

    expect(DB::table('card_status_logs')->whereNull('user_id')->whereNull('from_card_status_id')->count())->toBe(1)
        ->and(Schema::hasColumn('card_status_logs', 'updated_at'))->toBeFalse();
});

test('a customer has one invoice per reference month', function () {
    $customerId = insertCustomer();
    insertInvoice(['customer_id' => $customerId]);
    insertInvoice(['customer_id' => $customerId, 'reference_month' => '2026-11-01']);

    expect(fn () => insertInvoice(['customer_id' => $customerId]))
        ->toThrow(UniqueConstraintViolationException::class, 'invoices_customer_id_reference_month_unique');
});

test('invoices are indexed by status and due date', function () {
    expect(indexDefinition('invoices_invoice_status_id_due_date_index'))->toContain('(invoice_status_id, due_date)');
});

test('payments get sequential receipt numbers from the sequence', function () {
    $first = DB::table('payments')->find(insertPayment());
    $second = DB::table('payments')->find(insertPayment());

    expect($second->receipt_number)->toBe($first->receipt_number + 1);
});

test('an invoice has at most one active payment', function () {
    $invoiceId = insertInvoice();
    insertPayment(['invoice_id' => $invoiceId]);

    expect(fn () => insertPayment(['invoice_id' => $invoiceId]))
        ->toThrow(UniqueConstraintViolationException::class, 'payments_one_active_per_invoice');
});

test('an invoice can be paid again after its payment is refunded', function () {
    $invoiceId = insertInvoice();
    insertPayment(['invoice_id' => $invoiceId, 'refunded_at' => now(), 'refund_reason' => 'Pagamento em duplicidade']);
    insertPayment(['invoice_id' => $invoiceId]);

    expect(DB::table('payments')->where('invoice_id', $invoiceId)->count())->toBe(2);
});

test('payments amount must be positive', function () {
    insertPayment(['amount' => 0]);
})->throws(QueryException::class, 'payments_amount_positive');

test('financial categories default to active non-system categories', function () {
    $category = DB::table('financial_categories')->find(insertFinancialCategory('expense'));

    expect($category->is_system)->toBeFalse()
        ->and($category->is_active)->toBeTrue()
        ->and(indexDefinition('financial_categories_id_entry_type_unique'))->toContain('UNIQUE INDEX')->toContain('(id, entry_type_id)');
});

test('financial entries start unpaid and are indexed for listings and cash flow', function () {
    $entry = DB::table('financial_entries')->find(insertFinancialEntry());

    expect($entry->paid_at)->toBeNull()
        ->and($entry->payment_id)->toBeNull()
        ->and($entry->payment_method_id)->toBeNull()
        ->and(indexDefinition('financial_entries_entry_type_id_entry_status_id_due_date_index'))->toContain('(entry_type_id, entry_status_id, due_date)')
        ->and(indexDefinition('financial_entries_paid_at_index'))->toContain('(paid_at)');
});

test('a financial entry must have the same type as its category', function () {
    $incomeCategoryId = insertFinancialCategory('income');

    insertFinancialEntry(['financial_category_id' => $incomeCategoryId, 'entry_type_id' => insertEntryType('expense')]);
})->throws(QueryException::class, 'financial_entries_category_entry_type_foreign');

test('a category with entries cannot change its type', function () {
    $categoryId = insertFinancialCategory('expense');
    insertFinancialEntry(['financial_category_id' => $categoryId]);

    DB::table('financial_categories')->where('id', $categoryId)->update(['entry_type_id' => insertEntryType('income')]);
})->throws(QueryException::class, 'financial_entries_category_entry_type_foreign');

test('a payment generates one financial entry per installment number', function () {
    $categoryId = insertFinancialCategory('income');
    $paymentId = insertPayment();
    insertFinancialEntry(['financial_category_id' => $categoryId, 'payment_id' => $paymentId, 'installment_number' => 1]);
    insertFinancialEntry(['financial_category_id' => $categoryId, 'payment_id' => $paymentId, 'installment_number' => 2]);

    expect(fn () => insertFinancialEntry(['financial_category_id' => $categoryId, 'payment_id' => $paymentId, 'installment_number' => 2]))
        ->toThrow(UniqueConstraintViolationException::class, 'financial_entries_payment_id_installment_number_unique');
});

test('an entry generated by a payment requires an installment number between 1 and 12', function (?int $installmentNumber) {
    insertFinancialEntry(['financial_category_id' => insertFinancialCategory('income'), 'payment_id' => insertPayment(), 'installment_number' => $installmentNumber]);
})->with([
    'missing' => [null],
    'above 12' => [13],
])->throws(QueryException::class, 'financial_entries_payment_installment_required');

test('a payment has between 1 and 12 installments', function (int $installments) {
    DB::table('payments')->where('id', insertPayment())->update(['installments' => $installments]);
})->with([
    'zero' => [0],
    'above 12' => [13],
])->throws(QueryException::class, 'payments_installments_range');
