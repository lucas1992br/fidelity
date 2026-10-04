# Cartão Fidelidade — Database Schema

<!-- inputs: project-description.md@sha256:a685bf13b06b user-stories.md@sha256:0293dc9dcc9d -->

## Overview

O modelo gira em torno do **titular** (`customers`), que assina um **plano** (`plans`), tem **dependentes** (`dependents`) e possui um **cartão** vigente (`cards`) retirado do estoque, onde os cartões entram por **lote** (`card_batches`). Cada mudança de status de cartão fica em `card_status_logs`, cada troca de plano em `plan_changes` e as notas em `customer_notes`. O faturamento gera **mensalidades** (`invoices`) por titular e mês, quitadas por **pagamentos** (`payments`), que por sua vez geram **lançamentos financeiros** (`financial_entries`) classificados por **categorias** (`financial_categories`). Usuários (`users`) recebem perfis via spatie/laravel-permission; auditoria fica em `activity_log` e os parâmetros da clínica em `settings` (spatie/laravel-settings).

Convenções: **Eloquent / Laravel 13 sobre PostgreSQL 18** — tabelas no plural em snake_case, `id bigint` autoincremento, FKs `<singular>_id`, `created_at`/`updated_at`, `deleted_at` onde há soft delete, pivôs da spatie com os nomes do pacote. **Todo valor categórico usa tabela lookup** com FK (decisão do desenvolvedor, seguindo a regra do harness): `card_statuses`, `invoice_statuses`, `payment_methods`, `kinships`, `entry_types`, `entry_statuses`, além de `roles`/`permissions`. Dinheiro é sempre `decimal(12,2)`. CPF é `char(11)` só com dígitos.

## Schema (DBML)

```dbml
// ---------------------------------------------------------------------------
// Lookup tables
// ---------------------------------------------------------------------------

Table card_statuses {
  id bigint [pk, increment]
  name varchar [not null, note: 'Rótulo pt-BR: Em estoque, Ativo...']
  slug varchar [unique, not null, note: 'in_stock, active, suspended, cancelled, lost, discarded']
  color varchar [not null, note: 'Tom do badge: blue, green, amber, red, gray']
  is_current boolean [not null, default: false, note: 'true = cartão vinculado e vigente (active, suspended)']
  is_reusable boolean [not null, default: false, note: 'true só para in_stock: pode ser vinculado']
  description text [null]
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp
}

Table invoice_statuses {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null, note: 'open, overdue, paid, cancelled']
  color varchar [not null]
  is_payable boolean [not null, default: false, note: 'true para open e overdue']
  description text [null]
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp
}

Table payment_methods {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null, note: 'pix, cash, card, boleto']
  description text [null]
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp
}

Table kinships {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null, note: 'spouse, child, father, mother, sibling, other']
  description text [null]
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp
}

Table entry_types {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null, note: 'income, expense']
  description text [null]
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp
}

Table entry_statuses {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null, note: 'pending, paid, cancelled']
  color varchar [not null]
  description text [null]
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp
}

// ---------------------------------------------------------------------------
// Users, roles & permissions (spatie/laravel-permission)
// ---------------------------------------------------------------------------

Table users {
  id bigint [pk, increment]
  name varchar [not null]
  email varchar [unique, not null]
  email_verified_at timestamp [null]
  password varchar [not null]
  is_active boolean [not null, default: true, note: 'false bloqueia o login (US-1.1, US-1.4)']
  remember_token varchar [null]
  created_at timestamp
  updated_at timestamp
}

Table roles {
  id bigint [pk, increment]
  name varchar [not null, note: 'admin, financeiro, atendente']
  guard_name varchar [not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (name, guard_name) [unique]
  }
}

Table permissions {
  id bigint [pk, increment]
  name varchar [not null, note: 'cards.check, sales.create, ... (17, spec §10.1)']
  guard_name varchar [not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (name, guard_name) [unique]
  }
}

Table model_has_roles {
  role_id bigint [ref: > roles.id, not null]
  model_type varchar [not null]
  model_id bigint [not null]

  indexes {
    (role_id, model_id, model_type) [pk]
    (model_id, model_type)
  }
}

Table model_has_permissions {
  permission_id bigint [ref: > permissions.id, not null]
  model_type varchar [not null]
  model_id bigint [not null]

  indexes {
    (permission_id, model_id, model_type) [pk]
    (model_id, model_type)
  }
}

Table role_has_permissions {
  permission_id bigint [ref: > permissions.id, not null]
  role_id bigint [ref: > roles.id, not null]

  indexes {
    (permission_id, role_id) [pk]
  }
}

// ---------------------------------------------------------------------------
// Plans, customers & dependents
// ---------------------------------------------------------------------------

Table plans {
  id bigint [pk, increment]
  name varchar [not null, note: 'Individual, Familiar...']
  description text [null]
  price decimal(12,2) [not null, note: 'Valor mensal']
  max_dependents smallint [not null, default: 0, note: '0 = sem dependentes; unsigned']
  is_active boolean [not null, default: true, note: 'Inativo não aparece em novas adesões/trocas']
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp [null]
}

Table customers {
  id bigint [pk, increment]
  plan_id bigint [ref: > plans.id, not null]
  name varchar [not null]
  cpf char(11) [unique, not null, note: 'Só dígitos; validado pela regra Cpf']
  birth_date date [not null]
  email varchar [null]
  phone varchar [not null, note: 'Celular/WhatsApp']
  phone_secondary varchar [null]
  zip_code char(8) [not null]
  street varchar [not null]
  number varchar [not null]
  complement varchar [null]
  district varchar [not null]
  city varchar [not null]
  state char(2) [not null]
  due_day smallint [not null, note: '1–28 (check constraint)']
  joined_at date [not null]
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp [null]

  indexes {
    name [note: 'GIN pg_trgm para busca parcial (ILIKE)']
  }
}

Table dependents {
  id bigint [pk, increment]
  customer_id bigint [ref: > customers.id, not null, note: 'cascadeOnDelete']
  kinship_id bigint [ref: > kinships.id, not null]
  name varchar [not null]
  cpf char(11) [null, unique, note: 'Opcional; único quando preenchido']
  birth_date date [not null]
  sequence smallint [not null, note: '1, 2, 3... nunca reaproveitado → 000123-02']
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp [null]

  indexes {
    (customer_id, sequence) [unique]
  }
}

Table plan_changes {
  id bigint [pk, increment]
  customer_id bigint [ref: > customers.id, not null]
  from_plan_id bigint [ref: > plans.id, null, note: 'null na adesão']
  to_plan_id bigint [ref: > plans.id, not null]
  from_price decimal(12,2) [null]
  to_price decimal(12,2) [not null]
  reason varchar [null]
  user_id bigint [ref: > users.id, not null]
  created_at timestamp
}

Table customer_notes {
  id bigint [pk, increment]
  customer_id bigint [ref: > customers.id, not null]
  user_id bigint [ref: > users.id, not null]
  body text [not null]
  created_at timestamp
  updated_at timestamp
}

// ---------------------------------------------------------------------------
// Card stock & cards
// ---------------------------------------------------------------------------

Table card_batches {
  id bigint [pk, increment]
  description varchar [not null, note: 'Ex.: Lote gráfica out/2026']
  supplier varchar [null, note: 'Gráfica']
  received_at date [not null]
  quantity integer [not null, note: 'Cartões efetivamente cadastrados']
  first_number varchar [null, note: 'Informativo (faixa)']
  last_number varchar [null, note: 'Informativo (faixa)']
  user_id bigint [ref: > users.id, not null]
  created_at timestamp
  updated_at timestamp
}

Table cards {
  id bigint [pk, increment]
  card_batch_id bigint [ref: > card_batches.id, null]
  number varchar [unique, not null, note: 'Exatamente como impresso no plástico']
  customer_id bigint [ref: > customers.id, null, note: 'null enquanto em estoque']
  card_status_id bigint [ref: > card_statuses.id, not null]
  is_current boolean [not null, default: false, note: 'Espelha card_statuses.is_current; mantido pelas Actions de cartão']
  assigned_at date [null]
  status_changed_at timestamp [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    card_status_id
    customer_id [unique, name: 'cards_one_current_per_customer', note: 'Índice único PARCIAL: WHERE is_current']
  }
}

Table card_status_logs {
  id bigint [pk, increment]
  card_id bigint [ref: > cards.id, not null]
  from_card_status_id bigint [ref: > card_statuses.id, null, note: 'null na entrada em estoque']
  to_card_status_id bigint [ref: > card_statuses.id, not null]
  reason varchar [null, note: 'Ex.: Suspensão automática: 32 dias em atraso']
  user_id bigint [ref: > users.id, null, note: 'null = sistema']
  created_at timestamp
}

// ---------------------------------------------------------------------------
// Billing
// ---------------------------------------------------------------------------

Table invoices {
  id bigint [pk, increment]
  customer_id bigint [ref: > customers.id, not null]
  plan_id bigint [ref: > plans.id, not null, note: 'Plano vigente na geração']
  reference_month date [not null, note: 'Sempre dia 1']
  amount decimal(12,2) [not null, note: 'Congelado na geração']
  due_date date [not null]
  invoice_status_id bigint [ref: > invoice_statuses.id, not null]
  paid_at date [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (customer_id, reference_month) [unique]
    (invoice_status_id, due_date)
  }
}

Table payments {
  id bigint [pk, increment]
  invoice_id bigint [ref: > invoices.id, not null]
  receipt_number bigint [unique, not null, note: 'default nextval(receipt_number_seq)']
  paid_at date [not null]
  amount decimal(12,2) [not null, note: '>= invoices.amount']
  payment_method_id bigint [ref: > payment_methods.id, not null]
  notes text [null]
  user_id bigint [ref: > users.id, not null, note: 'Quem registrou']
  refunded_at timestamp [null, note: 'Estorno (US-9.4)']
  refunded_by_user_id bigint [ref: > users.id, null]
  refund_reason varchar [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    invoice_id [unique, name: 'payments_one_active_per_invoice', note: 'Índice único PARCIAL: WHERE refunded_at IS NULL']
  }
}

// ---------------------------------------------------------------------------
// Finance
// ---------------------------------------------------------------------------

Table financial_categories {
  id bigint [pk, increment]
  entry_type_id bigint [ref: > entry_types.id, not null]
  name varchar [not null]
  is_system boolean [not null, default: false, note: 'Ex.: Mensalidades — não pode ser excluída']
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp
}

Table financial_entries {
  id bigint [pk, increment]
  entry_type_id bigint [ref: > entry_types.id, not null]
  financial_category_id bigint [ref: > financial_categories.id, not null]
  description varchar [not null]
  amount decimal(12,2) [not null]
  due_date date [not null]
  entry_status_id bigint [ref: > entry_statuses.id, not null]
  paid_at date [null, note: 'Base do fluxo de caixa']
  payment_method_id bigint [ref: > payment_methods.id, null]
  payment_id bigint [ref: - payments.id, null, unique, note: 'Preenchido quando gerado por mensalidade']
  supplier_or_payer varchar [null]
  notes text [null]
  user_id bigint [ref: > users.id, not null]
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp [null]

  indexes {
    (entry_type_id, entry_status_id, due_date)
    paid_at
  }
}

// ---------------------------------------------------------------------------
// Package-owned infrastructure
// ---------------------------------------------------------------------------

Table activity_log {
  id bigint [pk, increment]
  log_name varchar [null]
  description text [not null]
  subject_type varchar [null]
  subject_id bigint [null]
  event varchar [null]
  causer_type varchar [null]
  causer_id bigint [null]
  properties json [null]
  batch_uuid uuid [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (subject_type, subject_id)
    (causer_type, causer_id)
    log_name
  }
}

Table settings {
  id bigint [pk, increment]
  group varchar [not null, note: 'clinic']
  name varchar [not null]
  locked boolean [not null, default: false]
  payload json [not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (group, name) [unique]
  }
}
```

## Relationships

- Um **plano** tem muitos **titulares**; um **titular** pertence a um **plano**.
- Um **titular** tem muitos **dependentes**; um **dependente** pertence a um **titular** e a um **parentesco** (`kinships`).
- Um **titular** tem muitos **cartões** ao longo do tempo e **no máximo um vigente** (`is_current`); um **cartão** pertence opcionalmente a um **titular** (nulo em estoque).
- Um **lote** (`card_batches`) tem muitos **cartões**; um **cartão** pertence opcionalmente a um **lote**.
- Um **cartão** pertence a um **status de cartão** e tem muitos **registros de status** (`card_status_logs`), cada um com status de origem/destino e usuário opcional (nulo = sistema).
- Um **titular** tem muitas **trocas de plano** (`plan_changes`), cada uma referenciando plano de origem (opcional) e destino e o usuário que a fez.
- Um **titular** tem muitas **observações** (`customer_notes`), cada uma de um **usuário**.
- Um **titular** tem muitas **mensalidades**; uma **mensalidade** pertence a um **titular**, a um **plano** (vigente na geração) e a um **status de mensalidade**.
- Uma **mensalidade** tem muitos **pagamentos** ao longo do tempo (estornados ficam no histórico), mas **no máximo um ativo** (`refunded_at IS NULL`).
- Um **pagamento** pertence a uma **forma de pagamento**, ao **usuário** que registrou e, se estornado, ao **usuário** que estornou.
- Um **pagamento** gera exatamente um **lançamento financeiro** (1:1 via `financial_entries.payment_id`).
- Uma **categoria financeira** pertence a um **tipo de lançamento** e tem muitos **lançamentos**.
- Um **lançamento** pertence a um **tipo**, uma **categoria**, um **status de lançamento**, opcionalmente a uma **forma de pagamento**, e ao **usuário** que o criou.
- Um **lote** e cada **observação/troca/lançamento** registram o **usuário** autor.
- **users** e **roles** são muitos-para-muitos via **model_has_roles** (polimórfico, spatie); **roles** e **permissions** via **role_has_permissions**; permissões diretas via **model_has_permissions**.
- **activity_log** referencia polimorficamente o registro alterado (`subject`) e o autor (`causer`).

## Lookup Table Seeds

- **card_statuses** (`slug` → `name`, `color`, `is_current`, `is_reusable`):
  - `in_stock` → Em estoque, blue, false, true
  - `active` → Ativo, green, true, false
  - `suspended` → Suspenso, amber, true, false
  - `cancelled` → Cancelado, red, false, false
  - `lost` → Extraviado, gray, false, false
  - `discarded` → Inutilizado, gray, false, false
- **invoice_statuses** (`slug` → `name`, `color`, `is_payable`): `open` → Em aberto, blue, true · `overdue` → Em atraso, red, true · `paid` → Pago, green, false · `cancelled` → Cancelada, gray, false
- **payment_methods**: `pix` → PIX · `cash` → Dinheiro · `card` → Cartão · `boleto` → Boleto
- **kinships**: `spouse` → Cônjuge · `child` → Filho(a) · `father` → Pai · `mother` → Mãe · `sibling` → Irmão(ã) · `other` → Outro
- **entry_types**: `income` → Receita · `expense` → Despesa
- **entry_statuses** (`slug` → `name`, `color`): `pending` → Pendente, blue · `paid` → Pago/Recebido, green · `cancelled` → Cancelado, gray
- **roles**: `admin`, `financeiro`, `atendente`
- **permissions** (17): `cards.check`, `sales.create`, `dashboard.view`, `customers.view`, `customers.update`, `cards.manage`, `cards.stock.view`, `cards.stock.manage`, `plans.manage`, `billing.view`, `billing.pay`, `billing.refund`, `finance.view`, `finance.manage`, `reports.view`, `settings.manage`, `users.manage`
- **role_has_permissions**: `financeiro` → todas exceto `plans.manage`, `billing.refund`, `settings.manage`, `users.manage` · `atendente` → `cards.check`, `sales.create` · `admin` → recebe tudo via `Gate::before` (sem linhas)
- Seeds de domínio (não lookup, mas carga inicial):
  - **financial_categories**: Mensalidades (income, `is_system`), Outras receitas (income), Aluguel, Salários, Materiais, Marketing, Impostos, Outras despesas (expense)
  - **plans**: Individual, Familiar (spec §13)
  - **settings** (`group = clinic`): `default_due_day = 10`, `suspend_after_days = 30`, `invoice_generation_day`, `min_card_stock`, `card_number_pattern = null`, dados da clínica

## Notes & Conventions

- **Lookup em vez de enum** (decisão do desenvolvedor): nenhuma coluna string/enum para valores categóricos. Os enums PHP já existentes em `app/Enums` passam a espelhar os **slugs** das lookups (ex.: `CardStatus::Active->value === 'active'`), servindo para comparação no código; rótulo e cor vêm da tabela lookup. Seeders resolvem ids por `slug`, nunca por id fixo.
- **Um cartão vigente por titular**: índice único parcial `CREATE UNIQUE INDEX cards_one_current_per_customer ON cards (customer_id) WHERE is_current`. `cards.is_current` é denormalizado de `card_statuses.is_current` porque um índice parcial não pode consultar outra tabela; toda Action que muda status de cartão atualiza os dois campos na mesma transação.
- **Um pagamento ativo por mensalidade**: índice único parcial `payments (invoice_id) WHERE refunded_at IS NULL`. A spec original usava `invoice_id unique`, o que impediria pagar de novo após um estorno (US-9.4).
- **Recibo**: `payments.receipt_number` com `DEFAULT nextval('receipt_number_seq')` (sequence PostgreSQL), seguro para concorrência; não usar `max() + 1`.
- **Soft deletes**: `plans`, `customers`, `dependents`, `financial_entries`. Pagamentos e lançamentos **nunca** são excluídos fisicamente; estorno é marcado em `payments.refunded_*` e no `entry_status` do lançamento.
- **Check constraints**: `customers.due_day BETWEEN 1 AND 28`; `plans.max_dependents >= 0`; `plans.price >= 0`; `payments.amount > 0`.
- **Busca**: extensões `pg_trgm` e `unaccent` + índice GIN em `customers.name` para busca parcial sem diferenciar maiúsculas/acentos (US-5.1); CPF e número de cartão por igualdade (índices únicos já cobrem).
- **Datas**: `invoices.reference_month` sempre dia 1; dias em atraso são calculados (`hoje − due_date`), não armazenados.
- **Identificação do dependente** (`000123-02`) é calculada a partir do cartão vigente + `sequence`; não é armazenada.
- **Tabelas de pacote**: `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` (spatie/laravel-permission), `activity_log` (spatie/laravel-activitylog) e `settings` (spatie/laravel-settings) vêm das migrations publicadas pelos pacotes; não editar sua estrutura.
- **Infra do framework** (já existentes, não modeladas acima): `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`. `users` ganha apenas a coluna `is_active`.
- **Conceitos não persistidos**: *Vidas atendidas* e *Índice de inadimplência* são calculados em tempo de execução (`DashboardMetrics`); *Dias em atraso* idem; *Segunda via* e *Suspensão automática* são processos registrados em `card_status_logs` (sem tabela própria); *Estorno* é registrado em `payments.refunded_*`; *Dinheiro e formatos* é convenção de tipo/exibição.
- **Reingresso** (US-6.4, workflow 16): sem tabela nova — um novo cartão é vinculado ao mesmo `customers.id` (o índice parcial só restringe cartões vigentes) e `plan_changes.reason = 'Reingresso'` registra o retorno.
- **Planos do design**: o protótipo visual mostra um terceiro plano ("Familiar Plus", R$ 129,90, até 7 dependentes), mas o seed segue a spec (Individual, Familiar); planos são editáveis pelo Admin (US-3.1).

## Open Questions

- **Valores iniciais** de `invoice_generation_day` e `min_card_stock` nas configurações.
