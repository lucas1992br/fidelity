# Cartão Fidelidade — Project Phases

<!-- inputs: project-description.md@sha256:a685bf13b06b user-stories.md@sha256:0293dc9dcc9d database-schema.md@sha256:4e09694d75d7 -->

## Overview

A construção segue **fundação primeiro**: ambiente e pacotes (Phase 1), banco completo — lookups, tabelas de pacote e tabelas de domínio com índices parciais, sequence e check constraints (Phases 2–3) —, models com **todos** os relacionamentos, factories e base de testes (Phase 4), configurações da clínica, auditoria e testes de integridade do banco (Phase 5) e a fundação de frontend sobre o protótipo visual já existente (Phase 6). Só então entram os fluxos, na ordem da especificação: autenticação e autorização em quatro camadas (Phase 7), administração (Phase 8), **Clientes e cartões** (Phases 9–14), **Faturamento** (Phases 15–17), **Financeiro** (Phases 18–19), painel e relatórios (Phase 20) e o fechamento do MVP (Phase 21).

São **21 fases**. O **MVP** é o conjunto completo dos três módulos (decisão do projeto): a primeira release sai ao fim da **Phase 21**. Marco intermediário: ao fim da Phase 17, os módulos Clientes e cartões e Faturamento (todas as histórias High) estão operacionais. O deploy no servidor Ubuntu fica **fora do plano** (decisão do desenvolvedor). Telas sem desenho em `.spec/init/design/` são construídas no padrão do design system (tokens + componentes `x-ui.*`) usando a tela desenhada mais próxima como referência (decisão do desenvolvedor). Cada fase é entregue a um agente pelo número (`Phase 9`, `Phase 13.2`) e cabe em uma sessão.

**Conventions:**
- `[ ]` pending · `[x]` done in the codebase.
- Phases and sub-phases are numbered (`Phase 1`, `Phase 5.3`) for reference by AI agents.
- Business-logic tasks list the **feature tests** to generate; frontend-only tasks list validatable **acceptance criteria** and a **Design ref**.
- Padrões do código (valem para todas as fases): páginas Livewire 4 **single-file** em `resources/views/pages/**/⚡*.blade.php` e componentes em `resources/views/components/**/⚡*.blade.php` (padrão já existente); rotas com `Route::livewire()`; regra de negócio só em `app/Actions/<Área>/` (um método `handle()`, `DB::transaction()` quando grava em mais de uma tabela); Form Objects em `app/Livewire/Forms/`; `authorize()` no `mount()` **e** em cada método público que altera dados; enums de `app/Enums` espelham os `slug` das tabelas lookup; dinheiro `decimal(12,2)` com cast `decimal:2`, nunca float; testes Pest em `tests/Feature/<Área>/` rodando em PostgreSQL.

---

## Phase 1: Ambiente, configuração e pacotes

**Goal:** deixar o projeto em pt-BR, `America/Sao_Paulo`, PostgreSQL em todos os ambientes e com todos os pacotes aprovados instalados. · **Depends on:** none · **Covers:** Tech Stack da descrição do projeto, `docs/markdown/cartao-fidelidade-especificacao.md` §2

### Phase 1.1: Base do projeto e localização

- [x] **Task:** Projeto base Laravel 13 + Livewire 4 + Blaze + Tailwind CSS 4 + Vite 8 + Pest 5 + Pint + Larastan + Sail (PHP 8.5, `postgres:18-alpine`).
  - **Acceptance criteria:**
    - `composer.json`/`package.json` declaram as versões da tabela Tech Stack; `compose.yaml` sobe `laravel.test` e `pgsql`.
    - `composer test` executa pint `--test`, phpstan nível 7 e a suíte Pest.
  - **Traces:** Tech Stack (project-description)

- [ ] **Task:** Localização pt-BR e timezone.
  - **Acceptance criteria:**
    - `config/app.php`: `timezone` = `America/Sao_Paulo`; `.env.example` com `APP_NAME="Cartão Fidelidade"`, `APP_LOCALE=pt_BR`, `APP_FALLBACK_LOCALE=pt_BR`, `APP_FAKER_LOCALE=pt_BR`.
    - Pacote `lucascudo/laravel-pt-br-localization` instalado e arquivos publicados em `lang/pt_BR` (validação, auth, paginação, senhas).
    - `__('validation.required', ['attribute' => 'nome'])` retorna a mensagem em português.
  - **Feature tests:** `tests/Feature/Foundation/LocalizationTest.php`: mensagem de validação `required` sai em pt-BR → idioma padrão; `now()->timezoneName` é `America/Sao_Paulo` → timezone da clínica.
  - **Traces:** Tech Stack (Localização); US-1.1 (mensagens em pt-BR)

### Phase 1.2: PostgreSQL em dev, testes e CI

- [ ] **Task:** Apontar `.env.example`, `phpunit.xml` e o workflow de CI para PostgreSQL.
  - **Acceptance criteria:**
    - `.env.example`: `DB_CONNECTION=pgsql`, `DB_HOST=pgsql`, `DB_PORT=5432`, `DB_DATABASE=laravel`, `DB_USERNAME=sail`, `DB_PASSWORD=password` (padrão Sail); `QUEUE_CONNECTION=database` mantido.
    - `phpunit.xml`: `DB_CONNECTION=pgsql` e `DB_DATABASE=testing` (banco criado pelo script do Sail); sem SQLite.
    - `.github/workflows/tests.yml` declara um service `postgres:18` com healthcheck e exporta `DB_*` para o job; `composer setup` + `composer ci:check` passam no CI.
    - O script `post-create-project-cmd` deixa de criar `database/database.sqlite`; o arquivo `database/database.sqlite` é removido.
  - **Feature tests:** `tests/Feature/Foundation/DatabaseDriverTest.php`: `DB::connection()->getDriverName()` é `pgsql` → os índices parciais, a sequence e o `pg_trgm` exigem PostgreSQL nos testes.
  - **Traces:** Tech Stack (Banco de dados); database-schema (Convenções — PostgreSQL 18)

### Phase 1.3: Pacotes e limpeza

- [ ] **Task:** Instalar os pacotes backend aprovados e publicar suas configurações.
  - **Acceptance criteria:**
    - `composer require laravel/fortify spatie/laravel-permission spatie/laravel-activitylog spatie/laravel-settings barryvdh/laravel-dompdf maatwebsite/excel` concluído; `composer show --direct` lista todos.
    - Configs publicadas: `config/fortify.php`, `config/permission.php`, `config/activitylog.php`, `config/settings.php`, `config/excel.php`, `config/dompdf.php` (dompdf com papel A4 e fonte com acentuação).
    - As **migrations** dos pacotes são publicadas na Phase 2 (não aqui).
    - `composer test` continua verde.
  - **Traces:** Tech Stack (Autenticação, Permissões, Auditoria, Configurações, PDF, Excel)

- [ ] **Task:** Dependências de frontend: Chart.js e máscara de input.
  - **Acceptance criteria:**
    - `chart.js` adicionado ao `package.json` e importado em `resources/js/app.js` (registrado em `window.Chart` para uso via Alpine).
    - Verificar (com `search-docs`) se o Livewire 4 embute o plugin `@alpinejs/mask`; se embutir, documentar no PR e não adicionar dependência; se não, **pedir aprovação** ao desenvolvedor antes de adicionar `@alpinejs/mask` (open question da descrição).
    - `npm run build` passa.
  - **Traces:** Tech Stack (Gráficos); Open Questions da descrição (máscara de input)

- [ ] **Task:** Remover resíduos do scaffold.
  - **Acceptance criteria:**
    - Diretório `app/Enums/Enums/` (enums vazios duplicados `InvoiceStatus`, `Kinship`, `PaymentMethod`) removido; nenhuma referência a `App\Enums\Enums`.
    - `composer test` verde após a remoção.
  - **Traces:** database-schema (Notes — enums espelham slugs)

---

## Phase 2: Banco — extensões, lookups, tabelas de pacote e seeders base

**Goal:** criar extensões do PostgreSQL, as seis tabelas lookup, as tabelas dos pacotes spatie e os seeders idempotentes de lookups, perfis e admin. · **Depends on:** Phase 1 · **Covers:** `card_statuses`, `invoice_statuses`, `payment_methods`, `kinships`, `entry_types`, `entry_statuses`, `users`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`, `activity_log`, `settings`

### Phase 2.1: Extensões e usuários

- [ ] **Task:** Migration de extensões PostgreSQL e função `f_unaccent`.
  - **Acceptance criteria:**
    - Migration cria `pg_trgm` e `unaccent` (`CREATE EXTENSION IF NOT EXISTS`) e a função imutável `f_unaccent(text)` (wrapper de `unaccent('unaccent', $1)`), necessária para o índice GIN de busca em `customers.name`.
    - `down()` remove a função e as extensões.
  - **Feature tests:** `tests/Feature/Database/ExtensionsTest.php`: `select f_unaccent('Conceição')` retorna `Conceicao` → busca sem acento disponível; `select similarity('maria','mária')` executa → `pg_trgm` ativo.
  - **Traces:** database-schema (Notes — Busca); US-5.1

- [ ] **Task:** Coluna `users.is_active`.
  - **Acceptance criteria:**
    - Migration adiciona `is_active boolean not null default true` em `users` (estrutura restante do starter mantida).
    - `UserFactory` ganha o estado `inactive()`.
  - **Feature tests:** `tests/Feature/Database/UsersTableTest.php`: usuário criado sem informar `is_active` fica ativo → default do schema.
  - **Traces:** `users`; US-1.1, US-1.4

### Phase 2.2: Tabelas lookup

- [ ] **Task:** Migrations das seis tabelas lookup.
  - **Acceptance criteria:**
    - `card_statuses` (`name`, `slug` unique, `color`, `is_current`, `is_reusable`, `description`, `is_active`, timestamps); `invoice_statuses` (`name`, `slug` unique, `color`, `is_payable`, `description`, `is_active`); `payment_methods`, `kinships`, `entry_types` (`name`, `slug` unique, `description`, `is_active`); `entry_statuses` (`name`, `slug` unique, `color`, `description`, `is_active`) — exatamente como o DBML.
    - Booleans com os defaults do schema (`is_current`/`is_reusable`/`is_payable` = false, `is_active` = true).
  - **Traces:** `card_statuses`, `invoice_statuses`, `payment_methods`, `kinships`, `entry_types`, `entry_statuses`

- [ ] **Task:** `LookupSeeder` idempotente.
  - **Acceptance criteria:**
    - Faz upsert por `slug` dos valores da seção *Lookup Table Seeds* do schema: `card_statuses` (6, com `color`, `is_current` true só para `active`/`suspended`, `is_reusable` true só para `in_stock`), `invoice_statuses` (4, `is_payable` true para `open`/`overdue`), `payment_methods` (4), `kinships` (6), `entry_types` (2), `entry_statuses` (3).
    - Nunca depende de ids fixos; rodar duas vezes não duplica nem altera ids.
  - **Feature tests:** `tests/Feature/Database/LookupSeederTest.php`: slugs e flags exatamente como o schema → regras `is_current`/`is_reusable`/`is_payable` corretas; segunda execução mantém contagens e ids → idempotência.
  - **Traces:** `card_statuses`, `invoice_statuses`, `payment_methods`, `kinships`, `entry_types`, `entry_statuses`; database-schema (Lookup Table Seeds)

### Phase 2.3: Tabelas de pacote e perfis

- [ ] **Task:** Publicar as migrations dos pacotes spatie sem alterar a estrutura.
  - **Acceptance criteria:**
    - Migrations publicadas de `spatie/laravel-permission` (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`), `spatie/laravel-activitylog` (`activity_log` com `event` e `batch_uuid`) e `spatie/laravel-settings` (`settings`).
    - Estrutura conforme o DBML (chaves e índices do pacote); `php artisan migrate:fresh` e `migrate:rollback` funcionam.
  - **Traces:** `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`, `activity_log`, `settings`

- [ ] **Task:** `RolesAndPermissionsSeeder` com a matriz §10.1.
  - **Acceptance criteria:**
    - Limpa o cache do `PermissionRegistrar`; cria as 17 permissões (`cards.check`, `sales.create`, `dashboard.view`, `customers.view`, `customers.update`, `cards.manage`, `cards.stock.view`, `cards.stock.manage`, `plans.manage`, `billing.view`, `billing.pay`, `billing.refund`, `finance.view`, `finance.manage`, `reports.view`, `settings.manage`, `users.manage`) com `findOrCreate`.
    - Perfis `admin` (sem linhas em `role_has_permissions`), `financeiro` (todas exceto `plans.manage`, `billing.refund`, `settings.manage`, `users.manage`) e `atendente` (`cards.check`, `sales.create`), via `syncPermissions`.
    - Idempotente.
  - **Feature tests:** `tests/Feature/Database/RolesAndPermissionsSeederTest.php`: financeiro não tem `plans.manage`, `billing.refund`, `settings.manage`, `users.manage` e tem as outras 13 → matriz §10.1; atendente tem exatamente 2 permissões → perfil restrito; admin não tem linhas em `role_has_permissions` → acesso via `Gate::before`; segunda execução não duplica.
  - **Traces:** `roles`, `permissions`, `role_has_permissions`; US-1.3

- [ ] **Task:** Admin inicial e `DatabaseSeeder` seguro para produção.
  - **Acceptance criteria:**
    - `AdminUserSeeder` cria (ou mantém) um usuário admin a partir de `ADMIN_NAME`, `ADMIN_EMAIL` e `ADMIN_PASSWORD` do `.env` (variáveis documentadas no `.env.example`), atribuindo o perfil `admin`; não sobrescreve a senha se o usuário já existir.
    - `DatabaseSeeder` chama, nesta ordem: `LookupSeeder`, `RolesAndPermissionsSeeder`, `AdminUserSeeder` (os seeders de planos e categorias entram na Phase 3 e o `DemoSeeder`, só em `local`, na Phase 5); o `User::factory()` de exemplo do starter é removido.
  - **Feature tests:** `tests/Feature/Database/AdminUserSeederTest.php`: cria admin com perfil `admin` → acesso inicial; rodar de novo não troca a senha → idempotência.
  - **Traces:** `users`, `model_has_roles`; US-1.4

---

## Phase 3: Banco — tabelas de domínio

**Goal:** criar todas as tabelas de domínio com FKs, índices parciais, sequence e check constraints, além dos seeds de planos e categorias. · **Depends on:** Phase 2 · **Covers:** `plans`, `customers`, `dependents`, `plan_changes`, `customer_notes`, `card_batches`, `cards`, `card_status_logs`, `invoices`, `payments`, `financial_categories`, `financial_entries`

### Phase 3.1: Planos, titulares e dependentes

- [ ] **Task:** Migration de `plans`.
  - **Acceptance criteria:**
    - Colunas do DBML; `price decimal(12,2)`, `max_dependents smallint default 0`, `is_active default true`, soft deletes.
    - Check constraints `plans_price_non_negative (price >= 0)` e `plans_max_dependents_non_negative (max_dependents >= 0)` via `DB::statement`.
  - **Traces:** `plans`

- [ ] **Task:** Migration de `customers`.
  - **Acceptance criteria:**
    - Colunas do DBML; `cpf char(11) unique`, `zip_code char(8)`, `state char(2)`, `due_day smallint`, `joined_at date`, soft deletes; FK `plan_id` → `plans` com `restrictOnDelete`.
    - Check `customers_due_day_range (due_day BETWEEN 1 AND 28)`.
    - Índice GIN `customers_name_trgm` em `f_unaccent(name) gin_trgm_ops` (busca parcial sem acento/maiúsculas).
  - **Traces:** `customers`; US-5.1

- [ ] **Task:** Migration de `dependents`.
  - **Acceptance criteria:**
    - FK `customer_id` com `cascadeOnDelete`; FK `kinship_id` → `kinships`; `cpf char(11) null unique`; `sequence smallint`; `is_active default true`; soft deletes.
    - Unique `(customer_id, sequence)`.
  - **Traces:** `dependents`, `kinships`

- [ ] **Task:** Migrations de `plan_changes` e `customer_notes`.
  - **Acceptance criteria:**
    - `plan_changes`: `from_plan_id` nullable (adesão), `to_plan_id`, `from_price` nullable, `to_price`, `reason`, `user_id`; **só** `created_at` (sem `updated_at`).
    - `customer_notes`: `customer_id`, `user_id`, `body text`, timestamps.
    - FKs para `customers`, `plans` e `users` com `restrictOnDelete`.
  - **Traces:** `plan_changes`, `customer_notes`

### Phase 3.2: Estoque e cartões

- [ ] **Task:** Migrations de `card_batches`, `cards` e `card_status_logs`.
  - **Acceptance criteria:**
    - `card_batches` conforme DBML (`quantity integer`, `first_number`/`last_number` informativos, `user_id`).
    - `cards`: `number varchar unique`, `card_batch_id` nullable, `customer_id` nullable, `card_status_id` (índice), `is_current boolean default false`, `assigned_at date`, `status_changed_at timestamp`.
    - Índice único **parcial** `cards_one_current_per_customer ON cards (customer_id) WHERE is_current` via `DB::statement`; removido no `down()`.
    - `card_status_logs`: `card_id`, `from_card_status_id` nullable, `to_card_status_id`, `reason`, `user_id` nullable (sistema); só `created_at`.
  - **Traces:** `card_batches`, `cards`, `card_status_logs`, `card_statuses`

### Phase 3.3: Faturamento e financeiro

- [ ] **Task:** Migrations de `invoices` e `payments`.
  - **Acceptance criteria:**
    - `invoices`: `customer_id`, `plan_id`, `reference_month date`, `amount decimal(12,2)`, `due_date`, `invoice_status_id`, `paid_at date null`; unique `(customer_id, reference_month)`; índice `(invoice_status_id, due_date)`.
    - `payments`: sequence `receipt_number_seq` criada na migration; `receipt_number bigint not null unique default nextval('receipt_number_seq')`; `paid_at date`, `amount decimal(12,2)`, `payment_method_id`, `notes`, `user_id`, `refunded_at`, `refunded_by_user_id`, `refund_reason`, timestamps.
    - Índice único **parcial** `payments_one_active_per_invoice ON payments (invoice_id) WHERE refunded_at IS NULL`; check `payments_amount_positive (amount > 0)`.
    - `down()` remove índices, tabela e sequence.
  - **Traces:** `invoices`, `payments`, `invoice_statuses`, `payment_methods`

- [ ] **Task:** Migrations de `financial_categories` e `financial_entries`.
  - **Acceptance criteria:**
    - `financial_categories`: `entry_type_id`, `name`, `is_system default false`, `is_active default true`; índice único `financial_categories_id_entry_type_unique (id, entry_type_id)`.
    - `financial_entries`: colunas do DBML, `payment_id` nullable **unique** (1:1 com pagamento), `payment_method_id` nullable, `paid_at` nullable, soft deletes; índices `(entry_type_id, entry_status_id, due_date)` e `paid_at`.
    - FK composta `(financial_category_id, entry_type_id) → financial_categories (id, entry_type_id)` com `ON UPDATE RESTRICT` (via `DB::statement`): o tipo do lançamento é sempre o da categoria.
  - **Traces:** `financial_categories`, `financial_entries`, `entry_types`, `entry_statuses`

### Phase 3.4: Seeds de domínio

- [ ] **Task:** `PlanSeeder` e `FinancialCategorySeeder`.
  - **Acceptance criteria:**
    - `PlanSeeder` (idempotente por `name`): **Individual** (R$ 49,90, `max_dependents` 0) e **Familiar** (R$ 89,90, `max_dependents` 4), ativos — valores do protótipo de design; o terceiro plano do design ("Familiar Plus") **não** é semeado.
    - `FinancialCategorySeeder` (idempotente por `name` + tipo): Mensalidades (income, `is_system` true), Outras receitas (income), Aluguel, Salários, Materiais, Marketing, Impostos, Outras despesas (expense); tipo resolvido por slug.
    - `DatabaseSeeder` chama os dois após o `LookupSeeder`; `php artisan migrate:fresh --seed` termina sem erro.
  - **Feature tests:** `tests/Feature/Database/DomainSeedersTest.php`: existem exatamente 2 planos ativos com os preços acima → seed da spec §13; "Mensalidades" é a única categoria `is_system` e é de receita → categoria do pagamento automático; segunda execução não duplica.
  - **Traces:** `plans`, `financial_categories`; database-schema (Seeds de domínio)

---

## Phase 4: Models, relacionamentos, factories e base de testes

**Goal:** todos os models com relacionamentos completos, casts, soft deletes e factories com estados, prontos para os fluxos. · **Depends on:** Phase 3 · **Covers:** todas as tabelas do schema

### Phase 4.1: Enums e lookups

- [ ] **Task:** Enums espelhando os slugs das lookups.
  - **Acceptance criteria:**
    - Novos enums `App\Enums\EntryType` (`Income`=`income`, `Expense`=`expense`) e `App\Enums\EntryStatus` (`Pending`, `Paid`, `Cancelled`), com `label()` e, no `EntryStatus`, `color()`, no mesmo estilo dos enums existentes.
    - Enums existentes (`CardStatus`, `InvoiceStatus`, `Kinship`, `PaymentMethod`) mantidos; os `value` coincidem com os slugs.
    - Trait `App\Enums\Concerns\ResolvesLookup` usado por todos os seis enums: `->id(): int` resolve o id na lookup pelo slug (cache em memória por request) e `::fromId(int)` faz o caminho inverso.
  - **Feature tests:** `tests/Feature/Models/LookupEnumsTest.php`: para cada enum, os `cases()` coincidem com os slugs semeados → enums espelham as lookups; `label()`/`color()` do enum são iguais a `name`/`color` da lookup → UI consistente; `CardStatus::Active->id()` retorna o id da linha `active` → resolução sem id fixo.
  - **Traces:** `card_statuses`, `invoice_statuses`, `payment_methods`, `kinships`, `entry_types`, `entry_statuses`; database-schema (Notes — Lookup em vez de enum)

- [ ] **Task:** Models das seis tabelas lookup.
  - **Acceptance criteria:**
    - `App\Models\CardStatus`, `InvoiceStatus`, `PaymentMethod`, `Kinship`, `EntryType`, `EntryStatus`: `slug` com cast para o enum correspondente, booleans com cast, `scopeActive()`, relacionamentos `hasMany` inversos (ex.: `CardStatus::cards()`, `InvoiceStatus::invoices()`, `EntryType::categories()`/`entries()`).
    - Onde um arquivo usa o model e o enum de mesmo nome, o enum é importado com alias (`use App\Enums\CardStatus as CardStatusEnum`).
  - **Traces:** `card_statuses`, `invoice_statuses`, `payment_methods`, `kinships`, `entry_types`, `entry_statuses`

### Phase 4.2: Models de domínio

- [ ] **Task:** `User` com perfis e relacionamentos.
  - **Acceptance criteria:**
    - `HasRoles` (spatie), cast `is_active` boolean, `is_active` em `#[Fillable]`/fillable conforme o padrão do model atual; `scopeActive()`.
    - Relacionamentos: `customerNotes`, `planChanges`, `cardBatches`, `cardStatusLogs`, `payments` (quem registrou), `refundedPayments` (`refunded_by_user_id`), `financialEntries`.
    - Acessor `roleLabel` (Admin, Financeiro, Atendente).
  - **Traces:** `users`, `model_has_roles`, `model_has_permissions`

- [ ] **Task:** `Plan`, `Customer`, `Dependent`, `PlanChange`, `CustomerNote`.
  - **Acceptance criteria:**
    - `Plan`: SoftDeletes, `price` `decimal:2`, `max_dependents` int; `customers()`, `invoices()`, `scopeActive()`.
    - `Customer`: SoftDeletes, casts de datas (`birth_date`, `joined_at`); `plan()`, `dependents()`, `activeDependents()`, `cards()`, `currentCard()` (`hasOne` com `is_current = true`), `previousCards()` (cartões não vigentes), `invoices()`, `payments()` (`hasManyThrough` invoices), `planChanges()`, `notes()` (mais recentes primeiro).
    - `Dependent`: SoftDeletes, `customer()`, `kinship()`, `scopeActive()`.
    - `PlanChange`: `const UPDATED_AT = null`; `customer()`, `fromPlan()`, `toPlan()`, `user()`; preços `decimal:2`.
    - `CustomerNote`: `customer()`, `user()`.
  - **Traces:** `plans`, `customers`, `dependents`, `plan_changes`, `customer_notes`

- [ ] **Task:** `CardBatch`, `Card`, `CardStatusLog`.
  - **Acceptance criteria:**
    - `CardBatch`: `user()`, `cards()`; `received_at` date.
    - `Card`: `batch()`, `customer()`, `status()` (`card_status_id`), `statusLogs()`; casts `is_current` boolean, `assigned_at` date, `status_changed_at` datetime; scopes `inStock()`, `current()`, `withStatus(CardStatusEnum ...)`; helper `hasStatus(CardStatusEnum): bool`.
    - `CardStatusLog`: `const UPDATED_AT = null`; `card()`, `fromStatus()`, `toStatus()`, `user()` (nullable = sistema).
  - **Traces:** `card_batches`, `cards`, `card_status_logs`

- [ ] **Task:** `Invoice`, `Payment`, `FinancialCategory`, `FinancialEntry`.
  - **Acceptance criteria:**
    - `Invoice`: `customer()`, `plan()`, `status()`, `payments()`, `activePayment()` (`hasOne` com `refunded_at` nulo); casts `reference_month`/`due_date`/`paid_at` date e `amount` `decimal:2`; scopes `payable()`, `overdue()`, `forMonth()`.
    - `Payment`: `invoice()`, `method()`, `user()`, `refundedBy()`, `financialEntry()` (`hasOne`); casts; `isRefunded()`; `receipt_number` nunca preenchido pela aplicação (vem da sequence — `refresh()` após `create`).
    - `FinancialCategory`: `entryType()`, `entries()`, `scopeActive()`, `scopeOfType(EntryTypeEnum)`, `static monthlyFees()` (categoria de sistema "Mensalidades").
    - `FinancialEntry`: SoftDeletes; `type()`, `category()`, `status()`, `method()`, `payment()`, `user()`; `isAutomatic()` = `payment_id !== null`.
    - `Model::preventLazyLoading(! app()->isProduction())` ativado no `AppServiceProvider`.
  - **Traces:** `invoices`, `payments`, `financial_categories`, `financial_entries`

### Phase 4.3: Atributos calculados, factories e base de testes

- [ ] **Task:** Atributos calculados (não armazenados).
  - **Acceptance criteria:**
    - `Dependent::identification` = `{número do cartão vigente do titular}-{sequence:02}` (ex.: `000123-02`); sem cartão vigente usa o último cartão do titular; nunca é persistido.
    - `Invoice::daysOverdue` = dias entre `due_date` e hoje quando a fatura está `overdue` (ou `open` já vencida); 0 caso contrário.
    - `Customer::maskedCpf` (`***.456.789-**`) e `Customer::formattedCpf` usando `App\Support\Format`.
  - **Feature tests:** `tests/Feature/Models/CalculatedAttributesTest.php`: dependente sequence 2 com cartão `000123` → `000123-02`; após trocar o cartão vigente para `000200`, a identificação vira `000200-02` → calculada, não armazenada; fatura vencida há 32 dias (`Carbon::setTestNow`) → `daysOverdue` 32; fatura `paid` → 0.
  - **Traces:** `dependents`, `invoices`, `customers`; US-4.3, US-6.1, US-8.2

- [ ] **Task:** Factories com estados para todos os models de domínio.
  - **Acceptance criteria:**
    - Factories pt-BR (`fake('pt_BR')`/faker locale) para `Plan`, `Customer` (CPF válido gerado com dígitos verificadores), `Dependent`, `PlanChange`, `CustomerNote`, `CardBatch`, `Card`, `CardStatusLog`, `Invoice`, `Payment`, `FinancialCategory`, `FinancialEntry`.
    - Estados: `Card` → `inStock()`, `active()`, `suspended()`, `cancelled()`, `lost()`, `discarded()` sempre coerentes com `is_current`; `Customer` → `withCard(CardStatusEnum $status = Active)` cria o cartão vigente; `Plan` → `inactive()`, `withDependents(int)`; `Invoice` → `open()`, `overdue(int $days)`, `paid()`, `cancelled()`, `forMonth(string)`; `Payment` → `refunded()`; `FinancialEntry` → `income()`, `expense()`, `pending()`, `paid()`, `automatic()`.
    - Lookups resolvidas por `Enum->id()`, nunca por id fixo.
  - **Traces:** todas as tabelas de domínio; AGENTS.md (factories com estados)

- [ ] **Task:** Base de testes.
  - **Acceptance criteria:**
    - `Tests\TestCase` semeia, antes de cada teste de Feature, `LookupSeeder`, `RolesAndPermissionsSeeder` e `FinancialCategorySeeder` (via `$seed`/`$seeder` com um `TestBaseSeeder`).
    - Helpers globais em `tests/Pest.php`: `actingAsRole(string $role): User` (cria usuário ativo com o perfil e autentica) e `settings(array $overrides)` (altera `ClinicSettings` no teste — usado a partir da Phase 5).
    - O exemplo `toBeOne`/`something()` do scaffold é removido.
  - **Traces:** `roles`, `model_has_roles`; testing-best-practices

- [ ] **Task:** Testes de relacionamentos.
  - **Acceptance criteria:**
    - Cada relacionamento declarado nas tasks acima é exercitado ao menos uma vez.
  - **Feature tests:** `tests/Feature/Models/RelationshipsTest.php`: `Customer::currentCard` retorna só o cartão com `is_current`; `previousCards` lista os `lost`/`discarded`/`cancelled`; `Invoice::activePayment` ignora pagamento estornado; `Customer::activeDependents` ignora inativos e soft-deleted; `FinancialCategory::monthlyFees()` retorna a categoria de sistema → relacionamentos completos desde a fundação.
  - **Traces:** `customers`, `cards`, `invoices`, `payments`, `dependents`, `financial_categories`

---

## Phase 5: Configurações da clínica, auditoria e integridade do banco

**Goal:** `ClinicSettings` com valores padrão, auditoria nos models principais, proteção contra exclusão física e testes das garantias do banco. · **Depends on:** Phase 4 · **Covers:** `settings`, `activity_log`, US-12.2, garantias de `cards`, `payments`, `invoices`, `customers`, `plans`

### Phase 5.1: Configurações

- [ ] **Task:** `App\Settings\ClinicSettings` e migration de settings com valores iniciais.
  - **Acceptance criteria:**
    - Grupo `clinic` com `clinic_name` (string), `clinic_cnpj`, `clinic_address`, `clinic_phone` (nullable), `default_due_day` (int), `suspend_after_days` (int), `invoice_generation_day` (int), `min_card_stock` (int), `card_number_pattern` (nullable string).
    - Migration de settings (`database/settings/`) com: `clinic_name` = `Policlínica` (placeholder até os dados reais), demais dados da clínica nulos, `default_due_day` = 10, `suspend_after_days` = 30, `invoice_generation_day` = 20, `min_card_stock` = 50, `card_number_pattern` = null.
    - `migrate:fresh --seed` deixa a tabela `settings` preenchida.
  - **Feature tests:** `tests/Feature/Settings/ClinicSettingsTest.php`: valores padrão acima → parâmetros iniciais do programa; alterar `suspend_after_days` e reler → persistência imediata.
  - **Traces:** `settings`; US-12.1; database-schema (Open Questions — resolvida: dia 20 e estoque 50)

### Phase 5.2: Auditoria e não exclusão

- [ ] **Task:** Activity log nos models principais.
  - **Acceptance criteria:**
    - `LogsActivity` em `Customer`, `Dependent`, `Card`, `Plan`, `Invoice`, `Payment` e `FinancialEntry` com `logFillable()->logOnlyDirty()->dontSubmitEmptyLogs()`; causer = usuário autenticado (nulo nas rotinas).
    - Nomes de log por área (`customers`, `cards`, `billing`, `finance`).
  - **Feature tests:** `tests/Feature/Audit/ActivityLogTest.php`: atualizar só o telefone do titular grava um log com apenas `phone` em `properties` e o usuário como causer → registra só campos alterados e autor; salvar sem mudanças não grava log.
  - **Traces:** `activity_log`; US-12.2

- [ ] **Task:** Impedir exclusão física de pagamentos e lançamentos.
  - **Acceptance criteria:**
    - `Payment` (sem soft delete) lança exceção de domínio em `deleting`; `FinancialEntry` lança exceção em `forceDeleting`; soft delete de lançamento manual continua permitido.
  - **Feature tests:** `tests/Feature/Audit/NoPhysicalDeleteTest.php`: `$payment->delete()` lança exceção e o registro continua no banco; `$entry->forceDelete()` lança exceção; `$entry->delete()` faz soft delete → pagamentos e lançamentos nunca são excluídos fisicamente.
  - **Traces:** `payments`, `financial_entries`; US-12.2

### Phase 5.3: Integridade e dados de demonstração

- [ ] **Task:** Testes das garantias do banco.
  - **Acceptance criteria:**
    - Cobrem todos os índices parciais, a sequence e as check constraints criadas na Phase 3.
  - **Feature tests:** `tests/Feature/Database/IntegrityTest.php`: segundo cartão `is_current` para o mesmo titular lança `UniqueConstraintViolationException` → um cartão vigente por titular; cartão cancelado + novo ativo para o mesmo titular é aceito → reingresso possível; segundo pagamento ativo da mesma fatura falha, mas após marcar o primeiro como estornado um novo pagamento é aceito → estorno permite nova baixa; dois pagamentos recebem `receipt_number` consecutivos sem informar o valor → sequence; fatura duplicada `(customer_id, reference_month)` falha → idempotência; `due_day` 29, `price` negativo e `amount` 0 violam as checks; despesa ligada a categoria de receita e troca do tipo de categoria com lançamentos violam a FK composta → tipo do lançamento coerente com a categoria.
  - **Traces:** `cards`, `payments`, `invoices`, `customers`, `plans`, `financial_entries`; database-schema (Notes)

- [ ] **Task:** `DemoSeeder` (somente `local`).
  - **Acceptance criteria:**
    - Executado pelo `DatabaseSeeder` apenas quando `app()->isLocal()`.
    - Cria: 1 usuário por perfil (financeiro e atendente, senha `password`), 1 lote com 100 cartões em estoque (com log), 50 titulares com cartão vigente (mistura de ativos, suspensos e alguns cancelados), dependentes dentro do limite do plano, 6 meses de mensalidades com pagamentos (com recibo e receita automática), atrasos variados e algumas despesas manuais.
    - Os dados respeitam todas as regras (um vigente por titular, sequence de dependentes, receita 1:1 com pagamento).
  - **Feature tests:** `tests/Feature/Database/DemoSeederTest.php`: com `app()->detectEnvironment(fn () => 'local')`, o seeder roda e nenhum titular tem mais de um cartão vigente e todo pagamento ativo tem exatamente um lançamento → demo coerente com as regras; em `production` ele não roda.
  - **Traces:** `customers`, `dependents`, `cards`, `card_batches`, `invoices`, `payments`, `financial_entries`; spec §13

---

## Phase 6: Fundação de frontend

**Goal:** completar o design system em Blade (tokens, componentes, layouts, página 403 e placeholder) sobre o protótipo já portado. · **Depends on:** Phase 1 · **Covers:** design system, layout, componentes compartilhados

### Phase 6.1: Design system existente

- [x] **Task:** Tokens de design no tema Tailwind e fontes.
  - **Acceptance criteria:**
    - `resources/css/app.css` define no `@theme` as paletas guardian, embrace, success, warning, danger e ink, raios, sombras e fontes (Plus Jakarta Sans, Bricolage Grotesque, JetBrains Mono) iguais a `colors_and_type.css`; variante `compact` (< 900px).
    - Fontes carregadas via `bunny()` no `vite.config.js`.
  - **Design ref:** `.spec/init/design/colors_and_type.css`
  - **Traces:** design system

- [x] **Task:** Componentes base `x-ui.*` e ícones Lucide.
  - **Acceptance criteria:**
    - Existem `avatar`, `badge` (tons green/amber/red/blue/gray), `button` (primary/secondary/soft/ghost/link; sm/md/lg), `card`, `card-title`, `choice`, `icon` (SVG inline de `resources/svg/lucide`), `input`, `modal`, `pill`, `stat`, `table`, `toast`, todos com as classes do protótipo.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (estilos inline de botões, badges, cards, tabelas, modais e toast)
  - **Traces:** design system

- [x] **Task:** Layout autenticado com sidebar agrupada e header.
  - **Acceptance criteria:**
    - `resources/views/layouts/app.blade.php` com sidebar (grupos Atendimento, Faturamento, Financeiro, Sistema via `App\Support\Navigation`), item ativo destacado, modo compacto só com ícones abaixo de 900px, header com crumb + título, atalho de busca com `F2`, avatar do usuário, `payment-modal` e `toast` globais.
    - Pendências registradas em outras fases: remover o bypass de visitante da `Navigation` (Phase 7), contador real de inadimplentes (Phase 17), nome da clínica vindo das configurações (Phase 8).
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (`<aside>`, header e `NAV`)
  - **Traces:** US-1.2, US-5.1 (atalho F2)

- [x] **Task:** Helper `App\Support\Format` (dinheiro, CPF, iniciais).
  - **Acceptance criteria:**
    - `money()` → `R$ 1.234,56`; `maskCpf()` → `***.456.789-**`; `cpf()` → `123.456.789-09`; `initials()` ignora partículas curtas.
  - **Traces:** US-12.3; project-description (Dinheiro e formatos)

### Phase 6.2: Componentes e telas compartilhadas que faltam

- [ ] **Task:** Completar o `Format` com data, telefone e WhatsApp.
  - **Acceptance criteria:**
    - `Format::date(?CarbonInterface)` → `d/m/Y` (ou `—` se nulo); `Format::phone(string $digits)` → `(11) 98765-4321` / `(11) 3456-7890`; `Format::whatsappUrl(string $phone)` → `https://wa.me/55<dígitos>`; `Format::zip()` → `01234-567`.
    - `money()` aceita string decimal sem converter para float internamente (usa formatação por string/`number_format` sobre valor já `decimal:2`).
  - **Feature tests:** `tests/Unit/FormatTest.php`: dinheiro com milhar e centavos; máscara de CPF a partir de dígitos e de CPF mascarado; telefone celular e fixo; link `wa.me/55` só com dígitos → formatos da descrição do projeto.
  - **Traces:** US-8.5 (link WhatsApp), US-12.3; project-description (Dinheiro e formatos)

- [ ] **Task:** Componentes de formulário e navegação faltantes.
  - **Acceptance criteria:**
    - `x-ui.field` (label + slot + mensagem de erro `@error` + dica), `x-ui.select`, `x-ui.textarea`, `x-ui.tabs` (abas com borda inferior guardian como a ficha), `x-ui.empty-state`, `x-ui.progress` (barra de uso do lote), `x-ui.callout` (tons success/warning/danger/info, usado nos avisos dos modais) e paginação estilizada (`resources/views/vendor/pagination/tailwind.blade.php` publicada e ajustada aos tokens).
    - Máscaras de CPF, celular, CEP, data e dinheiro aplicadas via Alpine (`x-mask`, conforme a decisão da Phase 1.3).
    - Todos com foco visível (`shadow-focus`), estados de erro em danger e funcionam em largura de celular sem rolagem horizontal da página.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (abas da FICHA DO CLIENTE, barra de uso dos lotes em ESTOQUE, avisos dos modais) + `.spec/init/design/colors_and_type.css`
  - **Traces:** design system; US-4.1, US-2.3, US-4.7

- [ ] **Task:** Gráfico de barras `x-ui.bar-chart` com Chart.js via Alpine.
  - **Acceptance criteria:**
    - Recebe `labels` e `datasets` (com tom: guardian para receita/entradas, embrace-300 para despesa/saídas) e opcionalmente uma série de linha (previsto); legenda e tooltip em pt-BR com valores em `R$`.
    - Reage a mudanças de dados do Livewire (re-render sem duplicar o canvas) e destrói o gráfico ao sair da página (`wire:navigate`).
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (PAINEL — "Receita x despesa · últimos 12 meses")
  - **Traces:** US-10.6, US-11.1

- [ ] **Task:** Layout de visitante, página 403 e placeholder de tela.
  - **Acceptance criteria:**
    - `resources/views/layouts/guest.blade.php`: tela centralizada com a marca (ícone heart-pulse + nome da clínica) para login.
    - `resources/views/errors/403.blade.php` em pt-BR ("Você não tem permissão para acessar esta página"), com botão para a página inicial do perfil, no visual do design system.
    - `pages/⚡placeholder.blade.php` reproduz o bloco "Esta tela ainda não foi desenhada"/em construção do protótipo com botão "Voltar ao painel" (usado pelas rotas cujas telas chegam em fases posteriores).
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (TELAS NÃO INCLUÍDAS) + `colors_and_type.css`
  - **Traces:** US-1.3

- [ ] **Task:** Toast com tipo e link de recibo.
  - **Acceptance criteria:**
    - Evento `notify` aceita `title`, `description`, `type` (`success` padrão, `error`) e `receiptUrl`; com `receiptUrl` o botão "Imprimir recibo" abre o PDF em nova aba (o botão simulado do protótipo é removido).
    - Some após 6 s e pode ser fechado; `role="status"`.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (TOAST)
  - **Traces:** US-9.1

---

## Phase 7: Autenticação, perfis e autorização

**Goal:** login via Fortify, redirecionamento por perfil, mapa completo de rotas com `can:`, policies e autorização nos componentes Livewire. · **Depends on:** Phases 2, 4, 6 · **Covers:** US-1.1, US-1.2, US-1.3; `users`, `roles`, `permissions`, `model_has_roles`

### Phase 7.1: Login e sessão

- [ ] **Task:** Configurar Fortify só com login/logout.
  - **Acceptance criteria:**
    - `FortifyServiceProvider` registrado; `features` sem registro, verificação de e-mail, reset de senha por e-mail, atualização de perfil ou 2FA (senhas são redefinidas pelo Admin na Phase 8).
    - `Fortify::loginView()` renderiza a página de login (layout guest); `home` do Fortify = `/`.
  - **Traces:** US-1.1

- [ ] **Task:** Tela de login.
  - **Acceptance criteria:**
    - Campos e-mail e senha, "Lembrar de mim", botão "Entrar"; form POST para a rota `login` do Fortify com `@csrf`; erros em pt-BR exibidos no campo.
    - Visual no design system (layout guest, `x-ui.input`, `x-ui.button`), responsivo.
  - **Design ref:** sem tela desenhada — default no design system (`colors_and_type.css`, `x-ui.input`/`x-ui.button`; cartão branco como os modais do protótipo)
  - **Traces:** US-1.1

- [ ] **Task:** Regras de autenticação: usuário inativo, mensagem genérica e rate limit.
  - **Acceptance criteria:**
    - `Fortify::authenticateUsing`: credenciais inválidas → mensagem genérica (`auth.failed` em pt-BR), sem indicar se o e-mail existe; usuário com senha correta e `is_active = false` → "Sua conta está desativada. Procure o administrador." e não autentica.
    - Rate limiter `login`: 5 tentativas por minuto por `email|ip`; a 6ª retorna erro de throttle em pt-BR.
    - Logout (botão no header) encerra a sessão, invalida o token e redireciona para `/login`.
  - **Feature tests:** `tests/Feature/Auth/LoginTest.php`: usuário ativo entra; senha errada mostra mensagem genérica e e-mail inexistente mostra a mesma mensagem → não revela existência; usuário inativo com senha correta não entra → bloqueio `is_active`; 6ª tentativa em 1 minuto é bloqueada → rate limit; logout redireciona ao login; visitante em `/painel` é redirecionado ao login → todas as rotas exigem autenticação.
  - **Traces:** US-1.1, US-1.4 (desativado bloqueado no login)

### Phase 7.2: Rotas, redirecionamento e menu

- [ ] **Task:** `Gate::before` para admin.
  - **Acceptance criteria:**
    - No `AppServiceProvider::boot()`: `Gate::before(fn (User $user) => $user->hasRole('admin') ? true : null)`.
  - **Feature tests:** `tests/Feature/Auth/AdminGateTest.php`: admin passa em `Gate::allows('users.manage')` e `billing.refund` sem linhas em `role_has_permissions` → admin recebe tudo.
  - **Traces:** US-1.3

- [ ] **Task:** Mapa completo de rotas com `can:` e página inicial por perfil.
  - **Acceptance criteria:**
    - `routes/web.php` reproduz as rotas da spec §8 dentro de `auth`: `/` (`home`, controller invokable), `/painel`, `/consulta`, `/vendas/nova`, `/clientes`, `/clientes/{customer}`, `/clientes/{customer}/editar`, `/planos`, `/cartoes`, `/faturamento`, `/faturamento/inadimplentes`, `/recibos/{payment}`, `/financeiro/{lancamentos,contas-a-pagar,contas-a-receber,fluxo-de-caixa,categorias}`, `/relatorios`, `/configuracoes`, `/usuarios` — cada uma com o middleware `can:` da matriz e os nomes usados pela `Navigation`.
    - Rotas cujas telas ainda não existem apontam para `pages::placeholder` (cada fase troca o destino ao construir a tela); `/painel` e `/consulta` usam as páginas do protótipo já existentes.
    - `HomeController`: quem tem `dashboard.view` vai para `/painel`; quem não tem (atendente) vai para `/consulta`.
    - A rota `welcome` e a view `welcome.blade.php` são removidas.
  - **Feature tests:** `tests/Feature/Auth/HomeRedirectTest.php`: atendente em `/` → `/consulta`; financeiro e admin → `/painel`; após login, atendente cai em `/consulta` → roteamento por perfil.
  - **Traces:** US-1.2, US-1.3

- [ ] **Task:** Menu filtrado e header com o usuário real.
  - **Acceptance criteria:**
    - `Navigation::allows()` deixa de liberar visitantes (sem bypass); itens filtrados por `Gate::allows`.
    - Item "Nova venda" visível para quem tem `sales.create`; atendente vê apenas Consulta rápida e Nova venda; Planos, Configurações e Usuários só para admin.
    - Header mostra nome, perfil (`roleLabel`) e botão "Sair".
  - **Feature tests:** `tests/Feature/Auth/NavigationTest.php`: atendente vê exatamente 2 itens; financeiro não vê Planos/Configurações/Usuários; admin vê todos → sidebar por permissão.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (`NAV` + `HIDE` por perfil)
  - **Traces:** US-1.2

### Phase 7.3: Policies e autorização nos componentes

- [ ] **Task:** Policies por model.
  - **Acceptance criteria:**
    - `CustomerPolicy` (`viewAny`/`view` → `customers.view`, `create` → `sales.create`, `update` → `customers.update`, `export` → `customers.view`), `CardPolicy` (`check` → `cards.check`, `manage` → `cards.manage`, `viewStock` → `cards.stock.view`, `manageStock` → `cards.stock.manage`), `InvoicePolicy` (`viewAny`/`view` → `billing.view`, `pay` → `billing.pay`), `PaymentPolicy` (`viewReceipt` → `billing.view`, `refund` → `billing.refund` e não estornado), `FinancialEntryPolicy` (`viewAny` → `finance.view`, `create`/`settle` → `finance.manage`, `update`/`delete` → `finance.manage` **e** lançamento manual).
    - Policies descobertas automaticamente; nenhuma regra de negócio além de permissão + estado.
  - **Feature tests:** `tests/Feature/Auth/PoliciesTest.php` com dataset perfil × ação: atendente só passa em `CustomerPolicy::create` e `CardPolicy::check`; financeiro não passa em `PaymentPolicy::refund`; lançamento automático nega `update`/`delete` até para admin → cada ação mapeada à permissão.
  - **Traces:** US-1.3; `customers`, `cards`, `invoices`, `payments`, `financial_entries`

- [ ] **Task:** Autorização nos componentes Livewire do protótipo.
  - **Acceptance criteria:**
    - `components/billing/⚡payment-modal`: `authorize('billing.pay')` em `open()` e em `confirm()`; `components/cards/⚡replace-card-modal`: `authorize('cards.manage')` em `open()` e `confirm()`; `pages/⚡quick-search` autoriza `cards.check` no `mount()`; `pages/⚡dashboard` autoriza `dashboard.view` no `mount()`.
    - Convenção registrada para as próximas fases: todo método público que altera dados chama `$this->authorize(...)`.
  - **Feature tests:** `tests/Feature/Auth/LivewireAuthorizationTest.php`: atendente chamando `confirm` no payment-modal recebe 403; atendente no `mount` do dashboard recebe 403 → chamada direta ao Livewire bloqueada.
  - **Traces:** US-1.3

- [ ] **Task:** Matriz de acesso às rotas.
  - **Acceptance criteria:**
    - 403 renderiza `errors/403.blade.php`.
  - **Feature tests:** `tests/Feature/Auth/RouteAccessTest.php` (dataset): atendente recebe 403 em `/painel`, `/clientes`, `/cartoes`, `/faturamento`, `/financeiro/*`, `/relatorios`, `/configuracoes`, `/usuarios` e 200 em `/consulta` e `/vendas/nova`; financeiro recebe 403 em `/planos`, `/configuracoes`, `/usuarios`; admin recebe 200 em todas → middleware `can:` conforme §10.1.
  - **Traces:** US-1.3

---

## Phase 8: Usuários, configurações e segurança

**Goal:** telas de administração (usuários e configurações) e proteção de sessão/HTTPS em produção. · **Depends on:** Phase 7 · **Covers:** US-1.4, US-12.1, US-12.3; `users`, `model_has_roles`, `settings`

### Phase 8.1: Usuários

- [ ] **Task:** Actions de usuários.
  - **Acceptance criteria:**
    - `App\Actions\Users\CreateUser` (nome, e-mail único, senha com `Password::defaults()`, perfil `admin`/`financeiro`/`atendente` via `syncRoles`), `UpdateUser` (nome, e-mail, perfil), `SetUserActive` (ativa/desativa; **o admin não pode desativar a própria conta**), `ResetUserPassword` (nova senha definida pelo admin).
  - **Feature tests:** `tests/Feature/Users/UserActionsTest.php`: cria usuário com perfil e e-mail duplicado falha; trocar perfil substitui o anterior (um perfil por usuário); desativar a si mesmo lança erro de validação; usuário desativado não consegue logar → bloqueio no próximo login; reset grava hash novo.
  - **Traces:** US-1.4; `users`, `model_has_roles`

- [ ] **Task:** Tela de usuários (`pages/users/⚡index`).
  - **Acceptance criteria:**
    - Tabela: nome, e-mail, perfil (badge), status (Ativo/Inativo), ações Editar, Redefinir senha, Ativar/Desativar; botão "Novo usuário"; modais de criação/edição e de redefinição de senha.
    - `authorize('users.manage')` no `mount` e em cada ação; a rota `users.index` passa a apontar para a página.
    - Botão "Desativar" oculto na linha do próprio usuário.
  - **Feature tests:** `tests/Feature/Users/UsersPageTest.php`: financeiro recebe 403 no mount e em `save`; admin cria usuário pelo componente → tela usa as Actions com autorização.
  - **Design ref:** sem tela desenhada — default no design system; referência mais próxima: tabela de CLIENTES e modais de `.spec/init/design/Cartao Fidelidade.dc.html`
  - **Traces:** US-1.4

### Phase 8.2: Configurações e segurança

- [ ] **Task:** Tela de configurações (`pages/⚡settings`).
  - **Acceptance criteria:**
    - Campos: nome, CNPJ, endereço e telefone da clínica; `default_due_day` (1–28); `suspend_after_days` (≥ 1); `invoice_generation_day` (1–28); `min_card_stock` (≥ 0); `card_number_pattern` (opcional, validado como regex compilável — `@preg_match` não retorna `false`).
    - Salvar persiste em `ClinicSettings` e vale imediatamente; toast de sucesso; `authorize('settings.manage')` no mount e no `save`; rota `settings` aponta para a página.
    - A marca da sidebar e do layout guest passa a usar `clinic_name`.
  - **Feature tests:** `tests/Feature/Settings/SettingsPageTest.php`: `due_day` 29 rejeitado; regex inválida (`[a-`) rejeitada; salvar `suspend_after_days` = 15 e ler `app(ClinicSettings::class)` retorna 15 → vale imediatamente; financeiro recebe 403.
  - **Design ref:** sem tela desenhada — default no design system (formulário em `x-ui.card` com `x-ui.field`)
  - **Traces:** US-12.1; `settings`

- [ ] **Task:** HTTPS e cookie de sessão seguro em produção.
  - **Acceptance criteria:**
    - `AppServiceProvider`: em produção, `URL::forceHttps()`.
    - `config/session.php` lê `SESSION_SECURE_COOKIE`; `.env.example` documenta `SESSION_SECURE_COOKIE=true` para produção.
  - **Feature tests:** `tests/Feature/Security/ProductionSecurityTest.php`: com ambiente `production`, `url('/')` gera `https://` → HTTPS obrigatório; com `SESSION_SECURE_COOKIE=true` a config `session.secure` é `true`.
  - **Traces:** US-12.3

---

## Phase 9: Planos e estoque de cartões

**Goal:** CRUD de planos e o estoque completo (lotes por faixa/lista/CSV, prévia, inutilização, listagens). · **Depends on:** Phases 5, 7 · **Covers:** US-3.1, US-2.1, US-2.2, US-2.3, US-2.4; `plans`, `card_batches`, `cards`, `card_status_logs`

### Phase 9.1: Planos

- [ ] **Task:** Actions de planos.
  - **Acceptance criteria:**
    - `App\Actions\Plans\SavePlan` (cria/edita nome, descrição, preço `decimal(12,2)` ≥ 0, `max_dependents` ≥ 0, ativo) e `DeletePlan` (soft delete só para plano sem titulares; senão erro "Plano com titulares não pode ser excluído; desative-o.").
    - Alterar o preço **não** altera mensalidades já geradas (o valor é congelado em `invoices.amount`).
  - **Feature tests:** `tests/Feature/Plans/PlanActionsTest.php`: alterar preço de 89,90 para 99,90 mantém fatura existente em 89,90 → preço vale só para as próximas gerações; plano inativo some de `Plan::active()` e o titular que já o tem continua com ele; excluir plano com titular falha.
  - **Traces:** US-3.1; `plans`, `invoices`

- [ ] **Task:** Tela de planos (`pages/⚡plans`) com modal.
  - **Acceptance criteria:**
    - Grade de cards: nome, badge Ativo/Inativo, preço "/mês", rótulo de dependentes ("Não inclui dependentes" / "Até N dependentes"), quantidade de **titulares ativos** (com cartão vigente) e botão "Editar"; botão "Novo plano".
    - Modal com nome, descrição, preço (máscara de dinheiro), limite de dependentes e ativo; `authorize('plans.manage')` no mount e em `save`/`delete`; rota `plans.index` aponta para a página.
  - **Feature tests:** `tests/Feature/Plans/PlansPageTest.php`: contagem mostra só titulares com cartão vigente; financeiro recebe 403 no mount.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (PLANOS)
  - **Traces:** US-3.1

### Phase 9.2: Regras do estoque

- [ ] **Task:** Serviço central de mudança de status de cartão.
  - **Acceptance criteria:**
    - `App\Actions\Cards\ChangeCardStatus` (uso interno pelas Actions de cartão): atualiza `card_status_id`, espelha `is_current` a partir de `card_statuses.is_current`, preenche `status_changed_at` e grava `card_status_logs` (de, para, motivo, usuário nulo = sistema) — tudo na transação do chamador.
  - **Feature tests:** `tests/Feature/Cards/ChangeCardStatusTest.php`: mudar para `suspended` mantém `is_current` true e para `lost` define false → espelho do lookup; cada mudança grava um log com de/para/motivo/usuário.
  - **Traces:** `cards`, `card_status_logs`, `card_statuses`; database-schema (Notes — um cartão vigente)

- [ ] **Task:** Prévia de lote (`App\Actions\Cards\BuildCardBatchPreview`).
  - **Acceptance criteria:**
    - Modo **faixa**: prefixo opcional + início + fim + nº de dígitos gera `prefixo . str_pad(n, dígitos, '0', STR_PAD_LEFT)`; início > fim → erro "O número inicial deve ser menor ou igual ao final."; dígitos menores que o tamanho do número final → erro.
    - Modo **lista/CSV**: um número por linha (ou primeira coluna do CSV); só remove espaços nas pontas; linhas vazias ignoradas sem motivo.
    - Retorna `valid` e `ignored` (número + motivo: "Já cadastrado no estoque", "Repetido na lista", "Fora do padrão configurado"); padrão = `card_number_pattern` aplicado ao número completo.
    - Não gera números além dos informados.
  - **Feature tests:** `tests/Feature/Cards/BuildCardBatchPreviewTest.php`: faixa `000001`–`000005` gera 5 números com zeros à esquerda; prefixo `A-` aplicado; início maior que fim rejeitado; número já existente vai para ignorados como duplicado; repetido na própria lista ignorado; com padrão `^\d{6}$`, `12345` é ignorado; número com espaços nas pontas é aparado e mantém zeros/formatação interna.
  - **Traces:** US-2.1, US-2.2; `cards`, `settings`

- [ ] **Task:** `RegisterCardBatch`.
  - **Acceptance criteria:**
    - Em transação: recalcula a prévia no servidor (protege contra corrida), cria `card_batches` (`quantity` = cartões efetivamente criados, `first_number`/`last_number` informativos, `user_id`) e cada cartão válido com `in_stock`, `customer_id` nulo, `is_current` false e um `card_status_logs` de entrada (`from` nulo → `in_stock`).
    - Exige `cards.stock.manage`; lote sem nenhum número válido é rejeitado.
  - **Feature tests:** `tests/Feature/Cards/RegisterCardBatchTest.php`: faixa de 10 com 2 já existentes cria 8 cartões em estoque, `quantity` 8 e 8 logs de entrada → duplicados rejeitados; lista sem válidos não cria lote; usuário sem `cards.stock.manage` não consegue executar pelo componente.
  - **Traces:** US-2.1, US-2.2; `card_batches`, `cards`, `card_status_logs`

- [ ] **Task:** `DiscardCard`.
  - **Acceptance criteria:**
    - Só para cartão `in_stock`; motivo obrigatório; status → `discarded` com log via `ChangeCardStatus`.
    - Cartão `discarded` nunca volta a `in_stock` nem pode ser vinculado (validado de novo em `AssignCard`, Phase 10).
  - **Feature tests:** `tests/Feature/Cards/DiscardCardTest.php`: cartão em estoque vira `discarded` com log e motivo; cartão `active` não pode ser inutilizado pelo estoque; motivo vazio rejeitado.
  - **Traces:** US-2.4; `cards`, `card_status_logs`

### Phase 9.3: Telas do estoque

- [ ] **Task:** Tela de estoque (`pages/cards/⚡stock`).
  - **Acceptance criteria:**
    - Cards de resumo: **Em estoque** (com alerta "Abaixo do mínimo de N. Peça um novo lote." quando `in_stock < min_card_stock`), **Vinculados** (ativos + suspensos, com a quebra) e **Extraviados / inutilizados**.
    - Aba **Cartões**: tabela número, lote, status (badge), cliente vinculado, data (recebido/vinculado/inutilizado), ação "Inutilizar" só em `in_stock` com `cards.stock.manage`; filtros status, lote e número em `#[Url]`; paginação; sem N+1.
    - Aba **Lotes**: descrição, gráfica, data de recebimento, faixa, barra de uso (vinculados, disponíveis, inutilizados).
    - `authorize('cards.stock.view')` no mount; rota `cards.stock` aponta para a página.
  - **Feature tests:** `tests/Feature/Cards/StockPageTest.php`: com `min_card_stock` 50 e 38 em estoque o alerta aparece e com 60 não aparece → alerta de estoque baixo; filtro por status `in_stock` lista só cartões em estoque; atendente recebe 403.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (ESTOQUE)
  - **Traces:** US-2.3

- [ ] **Task:** Modal "Cadastrar lote".
  - **Acceptance criteria:**
    - Campos descrição, gráfica (opcional), data de recebimento; seletor de modo **Faixa** (prefixo, início, fim, dígitos) / **Lista** (textarea) / **CSV** (upload com `WithFileUploads`, `.csv`/`.txt`).
    - Botão "Pré-visualizar" mostra contagem e lista de válidos e de ignorados com motivo; "Confirmar cadastro" só habilitado com prévia válida; toast ao concluir e listagem atualizada.
    - `authorize('cards.stock.manage')` ao abrir, pré-visualizar e confirmar.
  - **Design ref:** sem tela desenhada — default no design system; referência mais próxima: MODAL SEGUNDA VIA de `.spec/init/design/Cartao Fidelidade.dc.html` (campos, `x-ui.choice` para o modo)
  - **Traces:** US-2.1, US-2.2

- [ ] **Task:** Modal "Inutilizar cartão".
  - **Acceptance criteria:**
    - Mostra o número, pede motivo obrigatório e confirma via `DiscardCard`; `authorize('cards.stock.manage')`.
  - **Design ref:** sem tela desenhada — default (`x-ui.modal` + `x-ui.textarea`)
  - **Traces:** US-2.4

---

## Phase 10: Nova venda

**Goal:** venda atômica: titular + cartão do estoque + adesão + dependentes + primeira mensalidade, com validação de cartão em tempo real. · **Depends on:** Phase 9 · **Covers:** US-4.1, US-4.2, US-4.3, US-4.4; `customers`, `dependents`, `cards`, `card_status_logs`, `plan_changes`, `invoices`

### Phase 10.1: Regras

- [ ] **Task:** Regra de validação `App\Rules\Cpf`.
  - **Acceptance criteria:**
    - Aceita CPF com ou sem máscara; normaliza para dígitos; rejeita tamanho ≠ 11, sequências repetidas (`11111111111`) e dígitos verificadores inválidos com "O CPF informado é inválido." (algoritmo da spec §7).
  - **Feature tests:** `tests/Unit/CpfRuleTest.php`: CPF válido com e sem máscara passa; dígito verificador errado falha; `000.000.000-00` e `111.111.111-11` falham; 10 dígitos falha.
  - **Traces:** US-4.1, US-4.3, US-7.1

- [ ] **Task:** Verificação de disponibilidade de cartão (`App\Actions\Cards\CheckCardAvailability`).
  - **Acceptance criteria:**
    - Recebe o número (aparado) e o usuário que consulta; retorna estado + mensagem: `in_stock` → "Disponível"; `active`/`suspended`/`cancelled` → "Já vinculado" (com `customers.view`: "Já vinculado a {nome}"); `lost`/`discarded` → "Extraviado/Inutilizado"; inexistente → "Não cadastrado no estoque".
    - Só `in_stock` é considerado disponível.
  - **Feature tests:** `tests/Feature/Cards/CheckCardAvailabilityTest.php`: cada status retorna a mensagem correta; atendente recebe "Já vinculado" **sem** nome e financeiro recebe "Já vinculado a Maria…" → sem expor terceiros ao atendente.
  - **Traces:** US-4.2, US-6.1, US-6.4

- [ ] **Task:** `AssignCard`.
  - **Acceptance criteria:**
    - Em transação, com `lockForUpdate` no cartão: exige `in_stock` (senão `ValidationException` com a mensagem de `CheckCardAvailability`); define `customer_id`, `assigned_at` = hoje e status `active` (ou o status informado, usado pela segunda via) via `ChangeCardStatus`, com log.
  - **Feature tests:** `tests/Feature/Cards/AssignCardTest.php`: cartão em estoque fica `active`, vigente, `assigned_at` hoje e com log; cartão já vinculado, extraviado, inutilizado ou inexistente é rejeitado; segundo vínculo vigente para o mesmo titular é barrado.
  - **Traces:** US-4.1, US-4.2; `cards`, `card_status_logs`

- [ ] **Task:** Data de vencimento e emissão de mensalidade.
  - **Acceptance criteria:**
    - `App\Support\Billing\DueDate::next(CarbonInterface $from, int $dueDay)`: próximo `due_day` **estritamente posterior** à data de adesão (se hoje é dia 10 e `due_day` 10, vence no dia 10 do mês seguinte).
    - `App\Actions\Billing\IssueInvoice(Customer, CarbonInterface $referenceMonth)`: cria a fatura `open` com `reference_month` dia 1, `due_date` = mês de referência no `due_day` do titular, `amount` = preço **atual** do plano e `plan_id` congelados; não duplica (retorna a existente/nula se já houver fatura do mês).
  - **Feature tests:** `tests/Feature/Billing/IssueInvoiceTest.php`: adesão em 05/10 com `due_day` 10 → vence 10/10 e referência 01/10; adesão em 10/10 → vence 10/11, referência 01/11; adesão em 25/10 com `due_day` 5 → vence 05/11; segunda emissão do mesmo mês não cria outra fatura; valor e plano congelados mesmo após mudar o preço do plano.
  - **Traces:** US-4.4, US-8.1; `invoices`

- [ ] **Task:** `AddDependent`.
  - **Acceptance criteria:**
    - Só se `dependentes ativos < plano.max_dependents`; ao atingir o limite: "O plano {nome} permite até {N} dependentes."; plano com 0: "O plano {nome} não inclui dependentes. Troque de plano para incluir."
    - `sequence` = maior `sequence` já usado pelo titular (incluindo inativos e soft-deleted) + 1 — nunca reaproveitado.
    - CPF opcional, validado pela regra `Cpf`, único entre dependentes (inclusive soft-deleted) quando informado; parentesco por `kinships`.
  - **Feature tests:** `tests/Feature/Customers/AddDependentTest.php`: plano Familiar (4) aceita o 4º e rejeita o 5º com a mensagem; Individual rejeita qualquer dependente; após inativar o dependente 2, o novo recebe sequence 4 (não 2) e a vaga é liberada → sequence nunca reaproveitado; CPF repetido entre dependentes rejeitado.
  - **Traces:** US-4.3, US-7.1; `dependents`

- [ ] **Task:** `CreateCustomer` (venda atômica).
  - **Acceptance criteria:**
    - Em **uma transação**: normaliza CPF/CEP/telefones para dígitos; cria o titular (`joined_at` = hoje); grava `plan_changes` de adesão (`from_plan_id` nulo, `to_plan_id`, `to_price`, `reason` "Adesão", usuário); `AssignCard`; dependentes via `AddDependent`; primeira mensalidade via `IssueInvoice` com `DueDate::next`.
    - Plano precisa estar ativo; CPF único entre titulares; qualquer falha desfaz tudo (não existe titular sem cartão).
  - **Feature tests:** `tests/Feature/Sales/CreateCustomerTest.php`: venda completa cria titular, cartão `active` com log, adesão, 2 dependentes (sequence 1 e 2) e 1 fatura `open` com valor cheio → venda atômica; cartão já vinculado → nada é persistido (sem titular, sem plan_change, sem fatura); plano inativo rejeitado; CPF já usado por outro titular rejeitado; dependentes acima do limite revertem a venda inteira.
  - **Traces:** US-4.1, US-4.3, US-4.4; `customers`, `plan_changes`, `cards`, `dependents`, `invoices`

### Phase 10.2: Tela de venda

- [ ] **Task:** Form Objects `CustomerForm` e `DependentForm`.
  - **Acceptance criteria:**
    - `CustomerForm`: nome, CPF (`Cpf` + único em `customers`), nascimento (data passada), celular obrigatório, telefone secundário e e-mail opcionais, CEP (8 dígitos), logradouro, número, complemento, bairro, cidade, UF (2 letras válidas), plano (ativo), `due_day` 1–28 (padrão `default_due_day`); atributos e mensagens em pt-BR.
    - `DependentForm`: nome, CPF opcional (`Cpf` + único em `dependents`), nascimento, parentesco (lookup `kinships` ativas).
    - Reutilizáveis na edição (Phase 12) ignorando o próprio registro na unicidade.
  - **Feature tests:** `tests/Feature/Sales/CustomerFormTest.php`: `due_day` vem pré-preenchido com `default_due_day`; `due_day` 0 e 29 rejeitados; CPF com máscara é normalizado e validado; plano inativo rejeitado.
  - **Traces:** US-4.1, US-4.3; `customers`, `dependents`, `kinships`

- [ ] **Task:** Página de nova venda (`pages/sales/⚡create`).
  - **Acceptance criteria:**
    - Seções em cards: **Cartão** (campo grande mono com foco automático; validação em tempo real com debounce mostrando o estado de `CheckCardAvailability` em `x-ui.callout`; Enter do leitor não envia o formulário), **Dados pessoais**, **Contato**, **Endereço** (CEP consulta `https://viacep.com.br/ws/{cep}/json/` via `fetch` no Alpine e preenche logradouro/bairro/cidade/UF; falha ou CEP inexistente mantém os campos editáveis com aviso), **Plano** (somente ativos, mostra preço e limite) e **Vencimento**, **Dependentes** (repeater; botão "Adicionar dependente" desabilitado ao atingir o limite; trocar o plano revalida a quantidade e mostra o erro).
    - Botão "Concluir venda" chama `CreateCustomer`; sucesso → redirect para `/consulta?q={número do cartão}` com toast "Venda concluída"; nenhum valor de mensalidade é mostrado.
    - `authorize('sales.create')` no mount e no `save`; rota `sales.create` aponta para a página; o botão "Novo cliente" da lista (Phase 11) usa a mesma rota.
  - **Feature tests:** `tests/Feature/Sales/SalePageTest.php`: atendente conclui venda e é redirecionado para a consulta com o número do cartão → fluxo do atendente; venda com cartão que deixou de estar em estoque entre a digitação e o envio é rejeitada no servidor; trocar de Familiar para Individual com 2 dependentes mostra erro e não salva; usuário sem `sales.create` recebe 403 no `save`.
  - **Design ref:** sem tela desenhada — default no design system; referências mais próximas em `.spec/init/design/Cartao Fidelidade.dc.html`: campo de cartão do MODAL SEGUNDA VIA, cards e campos da FICHA DO CLIENTE
  - **Traces:** US-4.1, US-4.2, US-4.3, US-4.4

---

## Phase 11: Consulta rápida e lista de clientes

**Goal:** busca unificada por nome, CPF, cartão (vigente ou antigo) e identificação de dependente, com resultado restrito ao atendente, e a lista filtrável de clientes. · **Depends on:** Phase 10 · **Covers:** US-5.1, US-5.2, US-5.3, US-4.5, US-12.3; `customers`, `cards`, `dependents`

### Phase 11.1: Busca

- [ ] **Task:** `App\Actions\Customers\SearchCustomers`.
  - **Acceptance criteria:**
    - Ordem de resolução: (1) número exato de cartão — vigente → titular; não vigente (`lost`/`discarded`/`cancelled`) → titular com `replacedCardNumber` e o número atual (ou nenhum, se não houver vigente); (2) identificação de dependente `{cartão}-{NN}` → titular + dependente destacado; (3) CPF (com ou sem máscara, 11 dígitos) → titular; (4) nome parcial com `f_unaccent(name) ILIKE f_unaccent('%termo%')` usando o índice trigram.
    - Retorna até 10 resultados como DTO `{customer, dependent?, replacedCardNumber?}` com relações carregadas (plano, cartão vigente, dependentes ativos).
  - **Feature tests:** `tests/Feature/QuickSearch/SearchCustomersTest.php`: "conceicao" encontra "Maria Conceição" → sem acento/maiúsculas; CPF mascarado e sem máscara encontram o titular; `000123-02` encontra o titular e destaca o dependente 2; número de cartão `lost` retorna o titular com aviso e o número atual → busca por cartão antigo; cartão cancelado encontra o titular; termo inexistente retorna vazio.
  - **Traces:** US-5.1, US-5.2; `customers`, `cards`, `dependents`

- [x] **Task:** Interface da consulta rápida (protótipo visual).
  - **Acceptance criteria:**
    - `pages/⚡quick-search` tem campo único grande com foco automático e debounce de 300 ms, botão Limpar, card de resultado com nome, CPF, badge grande de status, faixa de aviso de cartão substituído, bloco de plano/próxima mensalidade/pendências, dependentes com identificação e rodapé com ações; estado "Nenhum cliente encontrado".
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (CONSULTA RÁPIDA)
  - **Traces:** US-5.1

- [ ] **Task:** Ligar a consulta rápida aos dados reais e às restrições do atendente.
  - **Acceptance criteria:**
    - Substitui `DemoData` por `SearchCustomers`; remove os chips "Experimente" (dados de demonstração).
    - Com `billing.view`: plano com preço, próxima mensalidade (`open` mais próxima: vencimento + valor), pendências (quantidade e soma das `overdue`), CPF completo.
    - Sem `billing.view` (atendente): só plano (sem preço), CPF mascarado, nenhuma mensalidade/valor/pendência; cartão suspenso mostra apenas "Cartão suspenso — encaminhar ao financeiro".
    - "Abrir ficha" só com `customers.view`; "Registrar pagamento" só com `billing.pay`, havendo fatura pagável e cartão não cancelado (dispara `open-payment`, modal ligado na Phase 16).
    - Ao chegar com `?q=` (redirect da venda), o resultado já aparece.
  - **Feature tests:** `tests/Feature/QuickSearch/QuickSearchPageTest.php`: atendente não vê "R$", "Próxima mensalidade" nem "Pendências" e vê CPF mascarado → resultado restrito; atendente com titular suspenso vê "Cartão suspenso — encaminhar ao financeiro"; atendente não vê "Abrir ficha"; financeiro vê pendências com quantidade e valor e o botão "Registrar pagamento"; busca por cartão substituído mostra "O cartão X foi substituído. Cartão atual: Y."; usuário sem `cards.check` recebe 403.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (CONSULTA RÁPIDA)
  - **Traces:** US-5.1, US-5.2, US-5.3, US-12.3

### Phase 11.2: Lista de clientes

- [ ] **Task:** Página de clientes (`pages/customers/⚡index`).
  - **Acceptance criteria:**
    - Tabela paginada: cartão vigente, nome, CPF mascarado (`***.456.789-**`), plano, nº de dependentes ativos, status do cartão (badge; titular sem cartão vigente mostra o status do último cartão).
    - Filtros status, plano e busca (nome, CPF ou cartão, reaproveitando `SearchCustomers`/escopos) em `#[Url]`; botão "Novo cliente" → `sales.create`; clique na linha → `customers.show`.
    - Consultas com eager loading/`withCount` (sem N+1); `authorize('customers.view')` no mount; rota `customers.index` aponta para a página.
  - **Feature tests:** `tests/Feature/Customers/CustomersIndexTest.php`: filtro por status `suspended` lista só suspensos; filtro por plano; busca por CPF; CPF aparece mascarado na listagem → LGPD; filtros persistem na query string; atendente recebe 403.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (CLIENTES)
  - **Traces:** US-4.5, US-12.3

---

## Phase 12: Ficha do cliente

**Goal:** ficha com cabeçalho, abas, linha do tempo, observações, edição do cadastro e exportação LGPD. · **Depends on:** Phase 11 · **Covers:** US-4.6, US-4.7, US-4.8, US-4.9, US-12.4; `customers`, `customer_notes`, `plan_changes`, `card_status_logs`, `invoices`, `payments`

### Phase 12.1: Estrutura da ficha

- [ ] **Task:** Página da ficha (`pages/customers/⚡show`): cabeçalho e abas.
  - **Acceptance criteria:**
    - Cabeçalho: avatar com iniciais, nome, badge do status do cartão, subtítulo "Titular desde {joined_at} · Cartão {número}"; ações conforme permissão e status: "Segunda via" e "Trocar plano" (`cards.manage`; ligadas nas Phases 13/14), "Registrar pagamento" (`billing.pay`, fatura pagável e cartão não cancelado), "Editar" (`customers.update`), "Exportar dados" (`customers.view`).
    - Abas com `x-ui.tabs` e aba ativa em `#[Url]`: Resumo, "Dependentes · N", Mensalidades, Histórico, Observações.
    - `authorize('view', $customer)` no mount; rota `customers.show` aponta para a página; titular soft-deleted → 404.
  - **Feature tests:** `tests/Feature/Customers/CustomerShowTest.php`: financeiro abre a ficha e vê o CPF completo → completo apenas na ficha; atendente recebe 403; "Registrar pagamento" não aparece para cartão cancelado.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (FICHA DO CLIENTE)
  - **Traces:** US-4.7, US-12.3

- [ ] **Task:** Aba Resumo.
  - **Acceptance criteria:**
    - Bloco do cartão vigente: número, desde quando (`assigned_at`), plano com preço, dia de vencimento, uso de dependentes ("2 de 4" / "Não inclui").
    - "Cartões anteriores": número, status final (Extraviado/Inutilizado/Cancelado), motivo e data (do último `card_status_logs` de cada cartão).
    - Área de ações de status (Suspender/Reativar/Cancelar/Reingressar) com slots preenchidos na Phase 13.
    - Dados do titular: CPF completo, nascimento, celular, e-mail, endereço formatado, adesão.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (FICHA DO CLIENTE — aba resumo)
  - **Traces:** US-4.7

- [ ] **Task:** Abas Dependentes e Mensalidades (listagem).
  - **Acceptance criteria:**
    - Dependentes: identificação (`000123-02`), nome, parentesco, nascimento, status (ativo/inativo); ações de gestão entram na Phase 14.
    - Mensalidades: referência (`m/Y`), vencimento, valor, status (badge; atraso como "Em atraso · N dias"), ordenadas da mais recente para a mais antiga; ações de baixa/recibo/estorno entram na Phase 16.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (FICHA DO CLIENTE — abas dependentes e mensalidades)
  - **Traces:** US-4.7

### Phase 12.2: Histórico e observações

- [ ] **Task:** `App\Services\CustomerTimeline`.
  - **Acceptance criteria:**
    - Junta: adesão (`plan_changes` com `from_plan_id` nulo), trocas de plano e reingresso, mudanças de status de todos os cartões do titular (vínculo, segunda via, suspensões/reativações automáticas e manuais, cancelamento), mensalidades geradas, pagamentos e estornos, observações.
    - Retorna coleção de DTOs `{date, type, title, description, user}` com `user` = nome ou "sistema" quando nulo; ordenada da mais recente para a mais antiga (como o protótipo).
  - **Feature tests:** `tests/Feature/Customers/CustomerTimelineTest.php`: titular com adesão, segunda via, suspensão automática, pagamento e nota gera os 5 eventos em ordem por data → linha do tempo completa; suspensão automática mostra "sistema" como responsável; estorno aparece como evento próprio.
  - **Traces:** US-4.8; `plan_changes`, `card_status_logs`, `invoices`, `payments`, `customer_notes`

- [ ] **Task:** Aba Histórico.
  - **Acceptance criteria:**
    - Linha do tempo vertical com ponto colorido por tipo, data, título, descrição e responsável.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (FICHA DO CLIENTE — aba histórico)
  - **Traces:** US-4.8

- [ ] **Task:** Observações (`AddCustomerNote` + aba).
  - **Acceptance criteria:**
    - Campo de texto + "Adicionar"; nota vazia ou só com espaços rejeitada; grava autor e data; aparece no topo da lista com "{perfil} · {nome} · {data}" e na linha do tempo.
    - `authorize('view', $customer)` (`customers.view`) no método.
  - **Feature tests:** `tests/Feature/Customers/CustomerNotesTest.php`: nota vazia rejeitada; nota salva com autor e aparece primeiro; atendente não consegue chamar o método.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (FICHA DO CLIENTE — aba observações)
  - **Traces:** US-4.9; `customer_notes`

### Phase 12.3: Edição e exportação

- [ ] **Task:** Edição do titular (`UpdateCustomer` + `pages/customers/⚡edit`).
  - **Acceptance criteria:**
    - Edita dados pessoais, contato, endereço (com ViaCEP) e `due_day` com as mesmas validações da venda (`CustomerForm`, CPF único ignorando o próprio titular); não edita cartão, plano nem dependentes.
    - Mudança de `due_day` não altera faturas existentes (vale para as próximas).
    - `authorize('update', $customer)` no mount e no `save`; rota `customers.edit` aponta para a página; volta para a ficha com toast.
  - **Feature tests:** `tests/Feature/Customers/UpdateCustomerTest.php`: alterar `due_day` de 10 para 20 mantém a fatura aberta com vencimento no dia 10 → vale para as próximas; CPF de outro titular rejeitado; atendente recebe 403.
  - **Design ref:** sem tela desenhada — mesmo layout da página de nova venda (Phase 10) sem as seções Cartão, Plano e Dependentes
  - **Traces:** US-4.6; `customers`

- [ ] **Task:** Exportação dos dados do titular (LGPD).
  - **Acceptance criteria:**
    - `App\Actions\Customers\ExportCustomerData` gera um arquivo JSON (`titular-{id}-{data}.json`) com dados cadastrais, dependentes (incluindo inativos), cartões (atuais e anteriores, com histórico de status), mensalidades e pagamentos (incluindo estornos).
    - Botão "Exportar dados" na ficha; `authorize('export', $customer)`; a exportação é registrada no activity log.
  - **Feature tests:** `tests/Feature/Customers/ExportCustomerDataTest.php`: download contém as cinco seções com os registros do titular e nenhum dado de outro titular; atendente recebe 403; activity log registra a exportação.
  - **Traces:** US-12.4

---

## Phase 13: Gestão do cartão

**Goal:** segunda via, suspensão/reativação manual, cancelamento com destino das faturas e reingresso. · **Depends on:** Phase 12 · **Covers:** US-6.1, US-6.2, US-6.3, US-6.4; `cards`, `card_status_logs`, `invoices`, `plan_changes`

### Phase 13.1: Regras

- [ ] **Task:** `SuspendCard` e `ReactivateCard`.
  - **Acceptance criteria:**
    - `SuspendCard`: só cartão vigente `active`; motivo obrigatório; → `suspended` com log e usuário.
    - `ReactivateCard`: só `suspended` (nunca `cancelled`); motivo obrigatório; → `active` com log; aceita usuário nulo/motivo fixo quando chamado pelo pagamento (Phase 16).
    - Dependentes herdam o status (não há status próprio de dependente).
  - **Feature tests:** `tests/Feature/Cards/SuspendReactivateTest.php`: suspender ativo grava log com motivo e usuário; suspender suspenso falha; reativar cancelado falha → cancelamento definitivo; motivo vazio rejeitado.
  - **Traces:** US-6.2; `cards`, `card_status_logs`

- [ ] **Task:** `CancelInvoice` e `CancelCard`.
  - **Acceptance criteria:**
    - `CancelInvoice`: `open`/`overdue` → `cancelled`; outras situações rejeitadas.
    - `CancelCard`: cartão `active`/`suspended`; motivo obrigatório; opção `pending_invoices` = `cancel` (todas as `open`/`overdue` do titular → `cancelled`) ou `keep` (continuam cobráveis); cartão → `cancelled`, `is_current` false, com log; nunca volta ao estoque; em transação.
  - **Feature tests:** `tests/Feature/Cards/CancelCardTest.php`: opção `cancel` cancela as 2 faturas pendentes e mantém as pagas; opção `keep` mantém as faturas `overdue` (aparecem em inadimplentes); cartão cancelado não pode ser reativado nem vinculado de novo; titular cancelado não é cobrado pela geração mensal (verificado na Phase 15).
  - **Traces:** US-6.3; `cards`, `invoices`

- [ ] **Task:** `ReplaceCard` (segunda via).
  - **Acceptance criteria:**
    - Em transação, com lock nos dois cartões: motivo `lost` (perda/roubo) ou `discarded` (dano); novo cartão precisa estar `in_stock`; cartão antigo → motivo escolhido (`is_current` false) **antes** de vincular o novo (respeita o índice parcial); novo cartão vinculado ao titular **herdando o status** do antigo (`active` ou `suspended`), `assigned_at` = hoje; logs nos dois cartões ("Segunda via: substituído por X" / "Segunda via do cartão Y").
  - **Feature tests:** `tests/Feature/Cards/ReplaceCardTest.php`: titular suspenso recebe novo cartão suspenso e o antigo vira `lost` → herda status; novo cartão fora do estoque rejeitado sem alterar o antigo; identificações dos dependentes passam a usar o novo número; busca pelo número antigo encontra o titular com aviso; antigo nunca pode ser vinculado de novo.
  - **Traces:** US-6.1; `cards`, `card_status_logs`

- [ ] **Task:** `ReenrollCustomer` (reingresso).
  - **Acceptance criteria:**
    - Pré-condição: titular sem cartão vigente e cujo último cartão está `cancelled`.
    - Plano escolhido ativo e com `max_dependents` ≥ dependentes ativos; senão "O plano {nome} permite até {N} dependentes; inative {k} dependente(s) antes do reingresso."
    - Em uma transação: atualiza `due_day` e `plan_id`; `AssignCard` (novo cartão `active` com log); `plan_changes` com `reason` "Reingresso" (planos e preços de/para); nova mensalidade com valor cheio no próximo `due_day` (`IssueInvoice` + `DueDate::next`) — se já existir fatura para o mês calculado (faturas mantidas/canceladas no cancelamento), usa o mês seguinte.
    - Dependentes mantêm o `sequence`; faturas mantidas continuam cobráveis.
  - **Feature tests:** `tests/Feature/Cards/ReenrollCustomerTest.php`: titular cancelado volta com cartão ativo, plan_change "Reingresso" e fatura nova → reingresso; titular com cartão vigente não pode reingressar; plano com limite menor que os dependentes ativos bloqueia informando quantos inativar; fatura mantida no cancelamento continua `overdue` após o reingresso; mês já faturado leva a nova mensalidade para o mês seguinte.
  - **Traces:** US-6.4; `cards`, `plan_changes`, `invoices`, `customers`

### Phase 13.2: Modais da ficha

- [x] **Task:** Interface do modal de segunda via (protótipo visual).
  - **Acceptance criteria:**
    - `components/cards/⚡replace-card-modal` tem escolha de motivo ("Perda ou roubo" / "Cartão danificado"), campo grande do novo cartão com validação em tempo real e aviso colorido, texto "O novo cartão herda o status do atual" e botão "Vincular novo cartão" desabilitado até o cartão ser válido.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (MODAL SEGUNDA VIA)
  - **Traces:** US-6.1

- [ ] **Task:** Ligar o modal de segunda via às regras reais.
  - **Acceptance criteria:**
    - Substitui `DemoData` por `CheckCardAvailability` e `ReplaceCard`; remove a lista "Em estoque agora" (dado de demonstração); recebe o titular pela ficha; `authorize('cards.manage')` em `open` e `confirm`; sucesso fecha o modal, mostra toast e atualiza a ficha.
  - **Feature tests:** `tests/Feature/Cards/ReplaceCardModalTest.php`: confirmar com cartão em estoque troca o cartão vigente; confirmar com cartão inválido não altera nada; atendente recebe 403 em `confirm`.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (MODAL SEGUNDA VIA)
  - **Traces:** US-6.1

- [ ] **Task:** Modais de suspender, reativar e cancelar.
  - **Acceptance criteria:**
    - Botões na aba Resumo conforme status: "Suspender" só `active`, "Reativar" só `suspended`, "Cancelar cartão" para `active`/`suspended`; nenhum perfil vê "Reativar" em cartão cancelado.
    - Modal de suspensão/reativação com motivo obrigatório; modal de cancelamento com motivo e escolha "Cancelar as N faturas pendentes (R$ X)" / "Manter as faturas (continuam cobráveis)" — mostra a contagem e o total; aviso de que o cancelamento é definitivo.
    - `authorize('cards.manage')` em abrir e confirmar.
  - **Feature tests:** `tests/Feature/Cards/CardStatusModalsTest.php`: cancelar pelo modal com "manter" deixa as faturas `overdue`; botão "Reativar" ausente para cartão cancelado; financeiro sem `cards.manage` (removida no teste) recebe 403.
  - **Design ref:** sem tela desenhada — default no design system; referência: MODAL SEGUNDA VIA e botões de ação da FICHA DO CLIENTE em `.spec/init/design/Cartao Fidelidade.dc.html`
  - **Traces:** US-6.2, US-6.3

- [ ] **Task:** Modal de reingresso.
  - **Acceptance criteria:**
    - Botão "Reingressar" só aparece para titular sem cartão vigente cujo último cartão está `cancelled`.
    - Campos: novo cartão (mesma validação em tempo real da venda), plano (somente ativos), `due_day` (padrão o atual do titular); aviso de bloqueio quando os dependentes não cabem; `authorize('cards.manage')`.
  - **Feature tests:** `tests/Feature/Cards/ReenrollModalTest.php`: botão ausente para titular com cartão ativo e presente para cancelado; confirmar reingressa o titular.
  - **Design ref:** sem tela desenhada — default; referência: MODAL SEGUNDA VIA de `.spec/init/design/Cartao Fidelidade.dc.html`
  - **Traces:** US-6.4

---

## Phase 14: Dependentes e troca de plano

**Goal:** gestão de dependentes após a venda e troca de plano com validação de limite. · **Depends on:** Phase 13 · **Covers:** US-7.1, US-7.2; `dependents`, `plan_changes`, `plans`

### Phase 14.1: Dependentes

- [ ] **Task:** `UpdateDependent` e `DeactivateDependent`.
  - **Acceptance criteria:**
    - `UpdateDependent`: nome, CPF opcional (válido e único entre dependentes, ignorando o próprio), nascimento, parentesco.
    - `DeactivateDependent`: `is_active` false; mantém `sequence`, identificação e histórico; libera vaga no limite.
    - Ambos exigem `customers.update`.
  - **Feature tests:** `tests/Feature/Customers/ManageDependentsTest.php`: inativar libera vaga e o próximo dependente recebe novo `sequence`; editar com CPF de outro dependente falha; identificação do inativo permanece igual.
  - **Traces:** US-7.1; `dependents`

- [ ] **Task:** Ações na aba Dependentes.
  - **Acceptance criteria:**
    - Botão "Adicionar dependente" (desabilitado com o limite atingido, mostrando a mensagem do plano; em plano sem dependentes explica que é preciso trocar de plano), modal de inclusão/edição com `DependentForm`, ação "Inativar" com confirmação.
    - `authorize('update', $customer)` em abrir/salvar/inativar.
  - **Feature tests:** `tests/Feature/Customers/DependentsTabTest.php`: adicionar pelo componente respeita o limite; atendente não consegue chamar `save`.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (FICHA DO CLIENTE — aba dependentes, botão com estado desabilitado)
  - **Traces:** US-7.1

### Phase 14.2: Troca de plano

- [ ] **Task:** `ChangePlan`.
  - **Acceptance criteria:**
    - Novo plano ativo e diferente do atual; bloqueia se dependentes ativos > `max_dependents` do novo plano: "Inative {k} dependente(s) para trocar para o plano {nome}."
    - Em transação: atualiza `customers.plan_id`; grava `plan_changes` (planos e preços de/para, motivo opcional, usuário); faturas já geradas não mudam.
  - **Feature tests:** `tests/Feature/Customers/ChangePlanTest.php`: Familiar com 3 dependentes → Individual é bloqueado informando 3; troca válida grava preços de/para e mantém a fatura aberta com o valor antigo; próxima geração usa o novo preço (verificado na Phase 15); plano inativo rejeitado.
  - **Traces:** US-7.2; `plan_changes`, `plans`, `invoices`

- [ ] **Task:** Modal "Trocar plano".
  - **Acceptance criteria:**
    - Lista só planos ativos com preço e limite, motivo opcional, aviso "O novo valor vale a partir da próxima mensalidade."; mostra o bloqueio de dependentes antes de confirmar; `authorize('cards.manage')`.
  - **Feature tests:** `tests/Feature/Customers/ChangePlanModalTest.php`: plano inativo não aparece nas opções; atendente recebe 403 em `confirm`.
  - **Design ref:** sem tela desenhada — default; referência: modais e botão "Trocar plano" da FICHA DO CLIENTE em `.spec/init/design/Cartao Fidelidade.dc.html`
  - **Traces:** US-7.2

---

## Phase 15: Rotinas de faturamento

**Goal:** geração mensal idempotente, marcação de atraso e suspensão automática, agendadas e auditadas. · **Depends on:** Phase 14 · **Covers:** US-8.1, US-8.2, US-8.3, US-12.2; `invoices`, `cards`, `card_status_logs`, `activity_log`

### Phase 15.1: Actions e comandos

- [ ] **Task:** `GenerateMonthlyInvoices` + comando `invoices:generate {--month=}`.
  - **Acceptance criteria:**
    - Action recebe o mês de referência; para cada titular com cartão vigente (`active` ou `suspended`), em lotes (`chunkById`), emite a fatura via `IssueInvoice` (preço **atual** do plano, `due_date` no `due_day`, `plan_id` vigente) se ainda não existir; retorna a quantidade criada.
    - Comando: sem `--month`, só executa quando `hoje.day == invoice_generation_day` e gera o **mês seguinte**; com `--month=YYYY-MM`, gera aquele mês em qualquer dia; registra no activity log (`log_name` `routines`) a quantidade criada e imprime o total.
  - **Feature tests:** `tests/Feature/Billing/GenerateInvoicesTest.php`: no dia 20 (`invoice_generation_day`) gera o mês seguinte para ativos e suspensos e ignora cancelados; em outro dia sem `--month` não gera nada; rodar duas vezes não duplica → idempotente; usa o preço atual do plano após troca de plano/alteração de preço; `--month=2026-12` gera dezembro; activity log registra a quantidade.
  - **Traces:** US-8.1, US-7.2, US-12.2; `invoices`, `activity_log`

- [ ] **Task:** `MarkOverdueInvoices` + comando `invoices:mark-overdue`.
  - **Acceptance criteria:**
    - Atualiza em massa `open` com `due_date < hoje` → `overdue`; não toca `paid`/`cancelled`; registra a quantidade no activity log.
  - **Feature tests:** `tests/Feature/Billing/MarkOverdueTest.php`: fatura que venceu ontem vira `overdue`; fatura que vence hoje continua `open`; `paid` e `cancelled` vencidas não mudam; activity log registra a quantidade.
  - **Traces:** US-8.2, US-12.2; `invoices`, `activity_log`

- [ ] **Task:** `SuspendDelinquentCards` + comando `cards:suspend-delinquent`.
  - **Acceptance criteria:**
    - Suspende cartões vigentes `active` cujo titular tem alguma fatura `overdue` com `hoje − due_date ≥ suspend_after_days`; usa `ChangeCardStatus` com motivo "Suspensão automática: {maior atraso} dias em atraso" e `user_id` nulo; registra a quantidade no activity log.
  - **Feature tests:** `tests/Feature/Cards/SuspendDelinquentTest.php`: com limite 30, atraso de 30 dias suspende e de 29 não → limite inclusivo; log com motivo "Suspensão automática: 32 dias em atraso" e usuário nulo; cartão já suspenso ou cancelado não é alterado; activity log registra a quantidade.
  - **Traces:** US-8.3, US-12.2; `cards`, `card_status_logs`, `activity_log`

### Phase 15.2: Agendamento

- [ ] **Task:** Agendar as rotinas.
  - **Acceptance criteria:**
    - `routes/console.php`: `invoices:mark-overdue` às 00:10, `cards:suspend-delinquent` às 00:20, `invoices:generate` às 01:00, todos com timezone `America/Sao_Paulo` e `withoutOverlapping()`; o comando `inspire` de exemplo é removido.
  - **Feature tests:** `tests/Feature/Billing/ScheduleTest.php`: os três eventos estão registrados no `Schedule` com as expressões cron `10 0 * * *`, `20 0 * * *` e `0 1 * * *` → rotinas diárias na ordem correta.
  - **Traces:** US-8.1, US-8.2, US-8.3

---

## Phase 16: Pagamentos, recibo e estorno

**Goal:** baixa de mensalidade com recibo numerado, receita automática e reativação do cartão; recibo PDF; estorno pelo admin. · **Depends on:** Phase 15 · **Covers:** US-9.1, US-9.2, US-9.3, US-9.4; `payments`, `invoices`, `financial_entries`, `cards`

### Phase 16.1: Regras

- [ ] **Task:** `RegisterPayment`.
  - **Acceptance criteria:**
    - Em transação com `lockForUpdate` na fatura: fatura `paid` → "Esta mensalidade já foi paga."; `cancelled` → "Esta mensalidade está cancelada."; valor pago < valor da fatura → "O valor recebido não pode ser menor que R$ X." (comparação sem float: centavos inteiros ou `bccomp`); valor maior aceito.
    - Efeitos: cria `payments` (recibo pela sequence, data, valor, forma, observação, usuário); fatura → `paid` com `paid_at`; cria `financial_entries` de receita, categoria "Mensalidades", status `paid`, `paid_at`, `due_date` da fatura, forma, `payment_id`, descrição "Mensalidade MM/AAAA - {titular}"; se o cartão vigente está `suspended` e não resta fatura `overdue` com atraso ≥ `suspend_after_days`, chama `ReactivateCard` com motivo "Reativação por pagamento"; cartão `cancelled` nunca é reativado.
    - `App\Actions\Billing\PaymentWillReactivateCard` expõe a mesma regra para o aviso do modal.
  - **Feature tests:** `tests/Feature/Billing/RegisterPaymentTest.php`: baixa cria pagamento com recibo, fatura paga e receita 1:1 na categoria Mensalidades; valor menor rejeitado e maior aceito; fatura paga e cancelada rejeitadas; dois pagamentos seguidos da mesma fatura resultam em um só → sem pagamento duplicado; recibos sequenciais; suspenso com única fatura atrasada é reativado com log "Reativação por pagamento"; suspenso com outra fatura ainda ≥ 30 dias continua suspenso; cancelado não é reativado.
  - **Traces:** US-9.1, US-9.2; `payments`, `invoices`, `financial_entries`, `financial_categories`, `cards`

- [ ] **Task:** `RefundPayment`.
  - **Acceptance criteria:**
    - Só `billing.refund` (admin); pagamento não estornado; em transação com lock: `refunded_at` = agora, `refunded_by_user_id`, `refund_reason` (opcional); fatura → `open` se `due_date >= hoje` ou `overdue` se vencida, `paid_at` nulo; lançamento vinculado → `cancelled`; nada é apagado; registrado no activity log (invariante do schema: `invoices.paid_at` preenchido ⇔ status `paid` ⇔ existe pagamento ativo).
    - O status do cartão não é alterado no estorno (a rotina diária reavalia a suspensão).
  - **Feature tests:** `tests/Feature/Billing/RefundPaymentTest.php`: estorno de fatura não vencida volta a `open` e de vencida volta a `overdue`, ambos com `paid_at` nulo; receita vinculada fica `cancelled`; pagamento continua no banco com `refunded_at`; estornar duas vezes falha; financeiro não consegue estornar; após estorno a fatura pode ser paga de novo com novo recibo.
  - **Traces:** US-9.4; `payments`, `invoices`, `financial_entries`, `activity_log`

- [ ] **Task:** Recibo em PDF (`ReceiptController`).
  - **Acceptance criteria:**
    - `GET /recibos/{payment}` (`can:billing.view` + `PaymentPolicy::viewReceipt`) retorna `Pdf::loadView('pdf.receipt', ...)->stream("recibo-{receipt_number}.pdf")`, gerado sob demanda e não armazenado.
    - Conteúdo: dados da clínica (`ClinicSettings`), nº do recibo, titular (nome e CPF), cartão, referência, valor pago por extenso e numérico, forma, data do pagamento; visual com os tokens do design system.
  - **Feature tests:** `tests/Feature/Billing/ReceiptTest.php`: resposta `application/pdf` com `Content-Disposition` contendo `recibo-{n}.pdf`; atendente recebe 403; nenhum arquivo gravado no storage.
  - **Traces:** US-9.3; `payments`, `settings`

### Phase 16.2: Interfaces

- [x] **Task:** Interface do modal de pagamento (protótipo visual).
  - **Acceptance criteria:**
    - `components/billing/⚡payment-modal` abre pelo evento `open-payment`, mostra titular/cartão/referência/vencimento, data, valor pré-preenchido, formas PIX/Dinheiro/Cartão/Boleto como `x-ui.choice`, aviso de reativação e "Confirmar baixa".
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (MODAL PAGAMENTO)
  - **Traces:** US-9.1

- [ ] **Task:** Ligar o modal de pagamento às regras reais.
  - **Acceptance criteria:**
    - Substitui `DemoData` por `Invoice`/`RegisterPayment`; formas vindas de `payment_methods` ativas; adiciona o campo "Observação" (`x-ui.textarea`); aviso de reativação via `PaymentWillReactivateCard`; erros de validação no campo.
    - Sucesso: toast "Pagamento registrado · recibo nº N" com `receiptUrl` e descrição de reativação quando houver; despacha `payment-registered` para as telas atualizarem.
    - Disponível a partir da consulta rápida, da ficha e da tela de mensalidades.
  - **Feature tests:** `tests/Feature/Billing/PaymentModalTest.php`: confirmar registra o pagamento e despacha `notify` com o número do recibo; valor menor mostra erro em `amount`; atendente recebe 403 em `open` e em `confirm` → método protegido.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (MODAL PAGAMENTO, TOAST)
  - **Traces:** US-9.1, US-9.2

- [ ] **Task:** Ações de baixa, recibo e estorno na aba Mensalidades da ficha.
  - **Acceptance criteria:**
    - Faturas pagáveis: "Registrar pagamento" (`billing.pay`); pagas: "Recibo" (abre o PDF do pagamento ativo); pagas com `billing.refund`: "Estornar" com modal de confirmação e motivo opcional.
    - A aba se atualiza ao receber `payment-registered` ou após estorno.
  - **Feature tests:** `tests/Feature/Billing/CustomerInvoicesTabTest.php`: financeiro não vê "Estornar" e admin vê; estorno pelo componente volta a fatura para `open`/`overdue`; financeiro chamando o método de estorno recebe 403.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (FICHA DO CLIENTE — aba mensalidades); modal de estorno no default do design system
  - **Traces:** US-9.1, US-9.3, US-9.4, US-4.7

---

## Phase 17: Mensalidades e inadimplentes

**Goal:** telas de acompanhamento de mensalidades e de cobrança dos inadimplentes, com exportação e contador no menu. · **Depends on:** Phase 16 · **Covers:** US-8.4, US-8.5; `invoices`, `customers`, `cards`

### Phase 17.1: Mensalidades

- [ ] **Task:** Consulta de resumo e filtros de mensalidades (`App\Queries\InvoicesQuery`).
  - **Acceptance criteria:**
    - Filtros: mês de referência **ou** intervalo de vencimento, status, plano e cliente (nome/CPF/cartão).
    - Resumo do conjunto filtrado: total, pago, em aberto e em atraso — valor (soma) e quantidade; canceladas fora do total.
    - Reutilizável pelo relatório de mensalidades (Phase 20).
  - **Feature tests:** `tests/Feature/Billing/InvoicesQueryTest.php`: resumo de um mês soma valores e conta por status corretamente; filtro por plano; filtro por intervalo de vencimento; canceladas não entram no total.
  - **Traces:** US-8.4; `invoices`

- [ ] **Task:** Página de mensalidades (`pages/billing/⚡index`).
  - **Acceptance criteria:**
    - Cards de resumo (total, pago, em aberto, em atraso — valor e quantidade); filtros em `#[Url]` (pílulas de status, período, plano, cliente); tabela paginada: cliente (link para a ficha), cartão, referência, vencimento, valor, status (badge; atraso "Em atraso · N dias"), ação "Registrar pagamento" (pagáveis, `billing.pay`) ou "Recibo" (pagas).
    - Atualiza ao receber `payment-registered`; `authorize('billing.view')` no mount; rota `billing.index` aponta para a página.
  - **Feature tests:** `tests/Feature/Billing/BillingIndexTest.php`: filtro status `overdue` lista só atrasadas e exibe "Em atraso · N dias"; filtros persistem na query string; atendente recebe 403.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (FATURAMENTO)
  - **Traces:** US-8.4

### Phase 17.2: Inadimplentes

- [ ] **Task:** `App\Queries\DelinquentsQuery`.
  - **Acceptance criteria:**
    - Titulares com ao menos uma fatura `overdue` (inclusive com cartão cancelado e faturas mantidas): nome, telefone, link `https://wa.me/55<dígitos>`, quantidade de faturas em atraso, valor total, maior atraso em dias, status do cartão (vigente ou último); ordenados pelo maior atraso desc; resumo com total de titulares e valor.
    - Uma única consulta agregada (sem N+1); reutilizada pela tela, exportações, painel (5 maiores atrasos) e relatório de inadimplência.
  - **Feature tests:** `tests/Feature/Billing/DelinquentsQueryTest.php`: titular com 2 faturas atrasadas (55 e 24 dias) aparece com quantidade 2, soma e maior atraso 55; ordenação pelo maior atraso; titular só com faturas abertas não aparece; titular cancelado com fatura mantida aparece.
  - **Traces:** US-8.5, US-6.3; `invoices`, `customers`

- [ ] **Task:** Página de inadimplentes (`pages/billing/⚡delinquents`).
  - **Acceptance criteria:**
    - Resumo "N titulares · R$ X em atraso"; botões "Exportar PDF" e "Exportar Excel"; tabela: titular (link para a ficha), WhatsApp (link `wa.me` em nova aba), faturas, total, maior atraso, status do cartão (badge).
    - `authorize('billing.view')`; rota `billing.delinquents` aponta para a página.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (INADIMPLENTES)
  - **Traces:** US-8.5

- [ ] **Task:** Exportações PDF e Excel de inadimplentes.
  - **Acceptance criteria:**
    - `App\Exports\DelinquentsExport` (`FromQuery`, `WithHeadings`, `WithMapping`, `WithColumnFormatting` com moeda e datas) e view `pdf/reports/delinquents.blade.php`, ambos a partir de `DelinquentsQuery`; cabeçalho com dados da clínica e data de emissão.
    - Downloads exigem `billing.view`.
  - **Feature tests:** `tests/Feature/Billing/DelinquentsExportTest.php`: `Excel::fake()` → `assertDownloaded` com as linhas da consulta; PDF retorna `application/pdf`; atendente recebe 403.
  - **Traces:** US-8.5

- [ ] **Task:** Contador de inadimplentes no menu.
  - **Acceptance criteria:**
    - O badge do item "Inadimplentes" usa a contagem de `DelinquentsQuery` (consulta leve, memoizada por request) em vez de `DemoData`; só é calculado quando o item está visível (`billing.view`); badge oculto quando zero.
  - **Feature tests:** `tests/Feature/Billing/DelinquentsMenuBadgeTest.php`: com 3 titulares em atraso o menu mostra 3; atendente não dispara a consulta (item oculto).
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (`<aside>` — badge no item Inadimplentes)
  - **Traces:** US-8.5

---

## Phase 18: Lançamentos e categorias

**Goal:** CRUD de categorias, lançamentos manuais com baixa e proteção dos lançamentos automáticos. · **Depends on:** Phase 16 · **Covers:** US-10.1, US-10.4, US-10.5; `financial_categories`, `financial_entries`, `entry_types`, `entry_statuses`

### Phase 18.1: Categorias

- [ ] **Task:** Actions de categorias.
  - **Acceptance criteria:**
    - `SaveFinancialCategory` (nome, tipo, ativo): categoria de sistema não pode ter o tipo alterado; `DeleteFinancialCategory`: proibido para `is_system`; categoria com lançamentos não é excluída (mensagem orientando desativar) nem tem o tipo alterado (bloqueado também pela FK composta do banco).
    - Categoria inativa não aparece para novos lançamentos.
  - **Feature tests:** `tests/Feature/Finance/FinancialCategoryActionsTest.php`: excluir "Mensalidades" falha; mudar o tipo de "Mensalidades" falha; categoria com lançamento não é excluída; categoria inativa some das opções de novo lançamento.
  - **Traces:** US-10.5; `financial_categories`

- [ ] **Task:** Página de categorias (`pages/finance/⚡categories`).
  - **Acceptance criteria:**
    - Lista agrupada por tipo (Receitas / Despesas) com nome, status, cadeado para categorias de sistema; modal de criação/edição; ações desativar/excluir ocultas quando bloqueadas.
    - Ver exige `finance.view`; criar/editar/excluir exige `finance.manage` (mount + métodos); rota `finance.categories` aponta para a página.
  - **Feature tests:** `tests/Feature/Finance/CategoriesPageTest.php`: usuário só com `finance.view` recebe 403 ao salvar; atendente recebe 403 no mount.
  - **Design ref:** sem tela desenhada — default no design system; referência: tabelas e badges de `.spec/init/design/Cartao Fidelidade.dc.html`
  - **Traces:** US-10.5

### Phase 18.2: Lançamentos

- [ ] **Task:** Actions de lançamentos manuais.
  - **Acceptance criteria:**
    - `CreateFinancialEntry`: tipo, categoria **do mesmo tipo e ativa**, descrição, valor > 0, vencimento, fornecedor/pagador, observação; opcionalmente já baixado (`paid_at` + forma → `paid`), senão `pending`.
    - `UpdateFinancialEntry` e `DeleteFinancialEntry` (soft delete) só para lançamentos manuais; automáticos (com `payment_id`) são recusados mesmo para admin.
    - `SettleFinancialEntry`: só `pending`; `paid_at` + forma → `paid`; já pago ou cancelado → "Este lançamento já foi baixado ou cancelado."
  - **Feature tests:** `tests/Feature/Finance/FinancialEntryActionsTest.php`: despesa com categoria de receita rejeitada → categoria do mesmo tipo; criar já baixado grava `paid_at` e forma; editar ou excluir lançamento automático falha; excluir manual faz soft delete; baixa de pendente vira `paid`; segunda baixa falha.
  - **Traces:** US-10.1, US-10.4; `financial_entries`, `entry_types`, `entry_statuses`, `payment_methods`

- [ ] **Task:** Página de lançamentos (`pages/finance/⚡entries`).
  - **Acceptance criteria:**
    - Filtros em `#[Url]`: tipo, categoria, status e período (vencimento ou pagamento); tabela paginada: data de vencimento, descrição, categoria, fornecedor/pagador, valor (receita em success, despesa em danger), status (badge), data de pagamento; automáticos com ícone de cadeado e link para a ficha do titular, sem ações.
    - Ações em manuais: editar, excluir, dar baixa (pendentes); botão "Novo lançamento"; `finance.view` no mount e `finance.manage` nos métodos; rota `finance.entries` aponta para a página.
  - **Feature tests:** `tests/Feature/Finance/EntriesPageTest.php`: filtro por tipo e status; lançamento automático exibe cadeado e não oferece editar; chamar `delete` de um automático pelo componente retorna 403.
  - **Design ref:** sem tela desenhada — default no design system; referência: FATURAMENTO de `.spec/init/design/Cartao Fidelidade.dc.html` (filtros em pílulas + tabela)
  - **Traces:** US-10.1

- [ ] **Task:** Modais de lançamento e de baixa.
  - **Acceptance criteria:**
    - Modal de lançamento com `FinancialEntryForm` (tipo como `x-ui.choice`, categorias filtradas pelo tipo, "Já pago?" revelando data e forma).
    - Modal de baixa (data + forma) reutilizado por Lançamentos e Contas a pagar/receber.
    - `authorize('finance.manage')` ao abrir e salvar.
  - **Design ref:** sem tela desenhada — default; referência: MODAL PAGAMENTO de `.spec/init/design/Cartao Fidelidade.dc.html`
  - **Traces:** US-10.1, US-10.4

---

## Phase 19: Contas a pagar, contas a receber e fluxo de caixa

**Goal:** visões de contas pendentes e o fluxo de caixa em regime de caixa com saldo inicial e acumulado. · **Depends on:** Phase 18 · **Covers:** US-10.2, US-10.3, US-10.4, US-10.6; `financial_entries`, `invoices`

### Phase 19.1: Contas

- [ ] **Task:** Página de contas a pagar (`pages/finance/⚡payables`).
  - **Acceptance criteria:**
    - Despesas `pending` em três grupos: **Vencidas** (`due_date` < hoje), **Vencendo em 7 dias** (hoje até hoje+7), **Futuras**; cada grupo com total; ação "Dar baixa" (modal da Phase 18) com `finance.manage`.
    - `finance.view` no mount; rota `finance.payables` aponta para a página.
  - **Feature tests:** `tests/Feature/Finance/PayablesPageTest.php`: despesa vencida ontem, vencendo em 3 dias e em 20 dias caem nos grupos certos; despesa paga ou cancelada não aparece; baixa remove da lista.
  - **Design ref:** sem tela desenhada — default; referência: cards de resumo e tabela de FATURAMENTO em `.spec/init/design/Cartao Fidelidade.dc.html`
  - **Traces:** US-10.2, US-10.4

- [ ] **Task:** Página de contas a receber (`pages/finance/⚡receivables`).
  - **Acceptance criteria:**
    - Lista unificada ordenada por vencimento: receitas manuais `pending` (com "Dar baixa") + mensalidades `open`/`overdue` (somente leitura, badge de status, link para a ficha/mensalidades); totais por origem.
    - `finance.view`; rota `finance.receivables` aponta para a página.
  - **Feature tests:** `tests/Feature/Finance/ReceivablesPageTest.php`: mostra receita manual pendente e mensalidade em atraso; mensalidade não oferece baixa; mensalidade paga não aparece.
  - **Design ref:** sem tela desenhada — default; referência: FATURAMENTO em `.spec/init/design/Cartao Fidelidade.dc.html`
  - **Traces:** US-10.3

### Phase 19.2: Fluxo de caixa

- [ ] **Task:** `App\Services\CashFlowReport`.
  - **Acceptance criteria:**
    - Regime de caixa por `paid_at`; ignora lançamentos `cancelled` e soft-deleted.
    - **Saldo inicial** = receitas pagas − despesas pagas com `paid_at` antes do início do período.
    - Visão **diária** (todos os dias do mês) e **mensal** (12 meses do ano): entradas, saídas, saldo do período e **saldo acumulado** (saldo inicial + soma progressiva).
    - **Previsto** opcional por `due_date`: lançamentos `pending` + mensalidades `open`/`overdue`.
    - Valores somados no banco (`SUM`) e retornados como string decimal.
  - **Feature tests:** `tests/Feature/Finance/CashFlowReportTest.php`: saldo inicial considera só pagamentos antes do período; acumulado do dia 3 = inicial + saldos dos dias 1–3; lançamento cancelado (ex.: receita de pagamento estornado) é ignorado; visão mensal agrega por mês; previsto inclui despesa pendente e mensalidade aberta pelo vencimento.
  - **Traces:** US-10.6; `financial_entries`, `invoices`

- [ ] **Task:** Página de fluxo de caixa (`pages/finance/⚡cash-flow`).
  - **Acceptance criteria:**
    - Alternância Diário / Mensal, seletor de mês ou ano e opção "Mostrar previsto" em `#[Url]`; card com saldo inicial; tabela com entradas, saídas, saldo e saldo acumulado; `x-ui.bar-chart` (entradas × saídas, linha de previsto quando ativa).
    - `finance.view`; rota `finance.cash-flow` aponta para a página.
  - **Feature tests:** `tests/Feature/Finance/CashFlowPageTest.php`: alternar para mensal mostra 12 linhas; atendente recebe 403.
  - **Design ref:** sem tela desenhada — default; referência: gráfico do PAINEL em `.spec/init/design/Cartao Fidelidade.dc.html`
  - **Traces:** US-10.6

---

## Phase 20: Painel e relatórios

**Goal:** indicadores reais no painel e os seis relatórios em PDF e Excel a partir de consultas compartilhadas. · **Depends on:** Phases 17, 19 · **Covers:** US-11.1, US-11.2; `cards`, `customers`, `dependents`, `invoices`, `payments`, `financial_entries`

### Phase 20.1: Painel

- [ ] **Task:** `App\Services\DashboardMetrics`.
  - **Acceptance criteria:**
    - Cartões ativos (`active`); cartões em estoque (`in_stock`, com flag de alerta `< min_card_stock`); vidas atendidas (titulares com cartão `active` + seus dependentes ativos); receita e despesa do mês (`financial_entries` `paid` com `paid_at` no mês atual); valor em aberto (`open` + `overdue`); valor em atraso (`overdue`); índice de inadimplência = titulares com ≥ 1 `overdue` ÷ titulares com cartão vigente (%; 0 quando não há titulares).
    - Série dos últimos 12 meses (receita × despesa pagas por mês), últimos pagamentos ativos (5 mais recentes) e 5 maiores atrasos (`DelinquentsQuery`).
  - **Feature tests:** `tests/Feature/Dashboard/DashboardMetricsTest.php`: cada indicador com um cenário controlado (ex.: 2 ativos + 1 suspenso, 3 dependentes ativos e 1 inativo → vidas atendidas 5; 1 de 3 titulares em atraso → 33,3%); lançamento cancelado não entra na receita do mês; série de 12 meses começa no mês correto; sem titulares o índice é 0.
  - **Traces:** US-11.1; `cards`, `customers`, `dependents`, `invoices`, `financial_entries`

- [x] **Task:** Interface do painel (protótipo visual).
  - **Acceptance criteria:**
    - `pages/⚡dashboard` tem a grade de indicadores `x-ui.stat` (com alerta), card "Receita x despesa · últimos 12 meses", card "Maiores atrasos" com link para inadimplentes e tabela "Últimos pagamentos".
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (PAINEL)
  - **Traces:** US-11.1

- [ ] **Task:** Ligar o painel ao `DashboardMetrics`.
  - **Acceptance criteria:**
    - Substitui `DemoData`; gráfico com `x-ui.bar-chart`; maiores atrasos com link para a ficha; últimos pagamentos com recibo, cliente, referência, forma, data e valor; alerta de estoque baixo no indicador.
  - **Feature tests:** `tests/Feature/Dashboard/DashboardPageTest.php`: painel exibe os valores calculados formatados em `R$`; atendente recebe 403.
  - **Design ref:** `.spec/init/design/Cartao Fidelidade.dc.html` (PAINEL)
  - **Traces:** US-11.1

### Phase 20.2: Relatórios

- [ ] **Task:** Consultas dos seis relatórios.
  - **Acceptance criteria:**
    - Em `app/Reports/`: `CustomersReport` (titulares e dependentes por plano/status do cartão), `InvoicesReport` (reaproveita `InvoicesQuery`: período e status), `DelinquencyReport` (reaproveita `DelinquentsQuery`), `PaymentsReport` (pagamentos por período agrupados por forma; estornados identificados e fora do total), `EntriesReport` (lançamentos por categoria no período, sem cancelados), `CashFlowReportExport` (reaproveita `CashFlowReport`).
    - Cada um expõe uma única consulta usada pelo Excel e pelo PDF.
  - **Feature tests:** `tests/Feature/Reports/ReportQueriesTest.php`: pagamentos agrupados por forma somam corretamente e excluem estornados; lançamentos por categoria ignoram cancelados; clientes filtrados por plano/status → mesmas regras das telas.
  - **Traces:** US-11.2; `customers`, `dependents`, `invoices`, `payments`, `financial_entries`

- [ ] **Task:** Exports Excel e views PDF.
  - **Acceptance criteria:**
    - `app/Exports/*Export` com `FromQuery`, `WithHeadings`, `WithMapping`, `WithColumnFormatting` (moeda `R$`, datas `d/m/Y`); views em `resources/views/pdf/reports/*.blade.php` com cabeçalho da clínica, período e totais.
    - Excel acima de 5.000 linhas roda em fila (`Excel::queue` para `storage/app/exports`), e a tela de relatórios avisa quando o arquivo fica pronto.
  - **Feature tests:** `tests/Feature/Reports/ReportExportsTest.php`: `Excel::fake()` → download do relatório de pagamentos com cabeçalhos esperados; acima do limite o export é enfileirado (`Excel::assertQueued`); PDF de cada relatório retorna `application/pdf`.
  - **Traces:** US-11.2

- [ ] **Task:** Página de relatórios (`pages/⚡reports`).
  - **Acceptance criteria:**
    - Seleção do relatório (cards com os seis), período (início/fim), filtros específicos (plano, status), botões "PDF" e "Excel"; exports enfileirados aparecem como "Processando…" e viram link de download quando prontos (polling leve).
    - `authorize('reports.view')` no mount e em cada download; rota `reports.index` aponta para a página.
  - **Feature tests:** `tests/Feature/Reports/ReportsPageTest.php`: financeiro baixa o relatório de inadimplência; atendente recebe 403 no mount e no método de download.
  - **Design ref:** sem tela desenhada — default no design system; referência: cards de PLANOS e botões de exportação de INADIMPLENTES em `.spec/init/design/Cartao Fidelidade.dc.html`
  - **Traces:** US-11.2

---

## Phase 21: Fechamento do MVP

**Goal:** remover o andaime do protótipo, revisar as quatro camadas de autorização e deixar o projeto verde para a primeira release. · **Depends on:** Phases 1–20 · **Covers:** US-1.3, US-12.2, US-12.3; todas as tabelas

### Phase 21.1: Limpeza e revisão

- [ ] **Task:** Remover `DemoData` e o placeholder.
  - **Acceptance criteria:**
    - `app/Support/Prototype/DemoData.php` e o namespace `App\Support\Prototype` removidos; `grep -r "DemoData" app resources routes tests` sem resultados.
    - Nenhuma rota aponta para `pages::placeholder`; a página é removida se não tiver mais uso.
  - **Traces:** US-11.1, US-5.1 (telas ligadas aos dados reais)

- [ ] **Task:** Revisão final das quatro camadas de autorização.
  - **Acceptance criteria:**
    - Toda rota em `auth` + `can:`; todo componente Livewire com `authorize()` no `mount()` e em cada método público que altera dados; policies usadas nos métodos; views com `@can` em menus, botões e blocos financeiros.
  - **Feature tests:** `tests/Feature/Auth/FullAccessMatrixTest.php` (dataset de todas as rotas × três perfis + visitante) e `tests/Feature/Auth/LivewireMutationsAuthorizationTest.php` (dataset de cada método que altera dados: venda, lote, inutilizar, segunda via, suspender, reativar, cancelar, reingresso, dependentes, troca de plano, notas, pagamento, estorno, lançamentos, categorias, planos, usuários, configurações) → atendente recebe 403 em tudo fora de `cards.check`/`sales.create`; financeiro em planos, estorno, configurações e usuários.
  - **Traces:** US-1.3, US-5.3

- [ ] **Task:** Auditoria e LGPD — verificação final.
  - **Acceptance criteria:**
    - Os sete models auditados registram criação/alteração com causer; rotinas registram contagens; nenhuma listagem exibe CPF completo (só a ficha).
  - **Feature tests:** `tests/Feature/Audit/LgpdReviewTest.php`: listagens de clientes, mensalidades, inadimplentes e consulta do atendente não contêm o CPF completo; ficha contém → CPF mascarado fora da ficha; pagamento e estorno geram entradas no activity log com causer.
  - **Traces:** US-12.2, US-12.3

- [ ] **Task:** Quality gate da release.
  - **Acceptance criteria:**
    - `vendor/bin/pint --test`, `phpstan analyse` (nível 7) e `php artisan test` verdes em PostgreSQL; `npm run build` sem erros; `php artisan migrate:fresh --seed` em `local` gera a base de demonstração e em `production` só lookups, perfis, admin, planos, categorias e settings.
    - CI do GitHub Actions verde.
  - **Traces:** Tech Stack (Qualidade, CI); todas as tabelas (`migrate:fresh --seed`)

## Open Questions

- **Dados da clínica** (nome, CNPJ, endereço, telefone): a Phase 5 semeia `clinic_name = "Policlínica"` e o restante nulo; o Admin preenche em Configurações (Phase 8) antes de emitir recibos.
- **Máscara de input:** confirmar se o Livewire 4 embute o `@alpinejs/mask`; se não, aprovar a dependência (Phase 1.3).
- **Primeira mensalidade quando a adesão cai no próprio `due_day`:** o plano adota o próximo `due_day` *estritamente posterior* (adesão em 10/10 com `due_day` 10 vence em 10/11). Confirmar.
- **Reingresso no mesmo mês de uma fatura mantida ou cancelada:** o plano gera a nova mensalidade para o mês seguinte ao já faturado (o unique `customer_id + reference_month` impede a duplicata). Confirmar.
- **Formato da exportação LGPD (US-12.4):** o plano usa JSON estruturado; confirmar se a clínica prefere PDF/Excel.
- **Limiar para Excel em fila (US-11.2):** o plano usa 5.000 linhas; ajustar se necessário.
- **Reativar dependente inativado:** as histórias só preveem inativar; reativação (respeitando o limite do plano) não foi planejada.
- **Tamanho máximo de um lote por faixa:** nenhum limite foi especificado; definir um teto evita prévias gigantes (ex.: 10.000 números).
