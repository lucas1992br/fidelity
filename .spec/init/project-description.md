# Cartão Fidelidade — Project Description

## Overview

Sistema web para **uma única clínica médica** (não multi-tenant) controlar seu **programa de cartão fidelidade**: titulares e dependentes assinam um **plano mensal**, recebem um **cartão físico pré-impresso** retirado do estoque e pagam **mensalidades**. O sistema acompanha a adimplência, **suspende automaticamente** cartões de inadimplentes, reativa ao pagar e consolida tudo no **financeiro** da clínica (receitas, despesas, contas a pagar/receber e fluxo de caixa). Acesso por navegador, responsivo, em **pt-BR** (timezone `America/Sao_Paulo`).

O sistema é dividido em três módulos: **(1) Clientes e cartões** — titulares, dependentes, planos, estoque de cartões e vínculo cartão↔cliente, consulta rápida e histórico; **(2) Faturamento** — mensalidades, baixas, inadimplência, suspensão automática, estorno e recibo PDF; **(3) Financeiro** — lançamentos, contas a pagar/receber, fluxo de caixa, painel de indicadores e relatórios PDF/Excel.

Há **três perfis**: **Admin** (acesso total), **Financeiro** (clientes, faturamento, financeiro, relatórios) e **Atendente** (**apenas vende e consulta o status do cartão**, sem ver valores, mensalidades ou pendências). A autorização é verificada em quatro camadas: rota, componente Livewire (mount + cada ação), Policy e view.

**MVP** = os três módulos completos conforme a especificação de referência `docs/markdown/cartao-fidelidade-especificacao.md` (fonte detalhada de modelo de dados, telas, rotas e permissões), entregues em 5 etapas: Base → Clientes e cartões → Faturamento → Financeiro → Implantação. Ficam **fora**: multi-clínica, portal/app do cliente, cobrança online integrada (boleto/PIX automático) e geração de números de cartão pelo sistema.

### Key Concepts

- **Titular (Customer):** cliente pagante; CPF obrigatório (armazenado com 11 dígitos, sem máscara), dia de vencimento `due_day` entre 1 e 28, um plano vigente. Não existe titular sem cartão vigente.
- **Dependente:** pessoa vinculada ao titular que usa o **mesmo cartão físico**; CPF opcional (único quando informado); identificado por `{numero_cartao}-{sequence:02}` (ex.: `000123-02`), calculado (não armazenado); `sequence` nunca é reaproveitado; herda o status do cartão do titular.
- **Plano:** nome, preço mensal (`decimal(12,2)`), `max_dependents` (0 = sem dependentes), ativo/inativo (inativo não aparece para novas adesões).
- **Lote de cartões (Card Batch):** remessa de cartões **já impressos** pela gráfica, cadastrada por **faixa** (prefixo opcional + início + fim + nº de dígitos) ou **lista/CSV**; duplicados ou fora de `card_number_pattern` são rejeitados e listados.
- **Cartão:** número impresso exatamente como no plástico (único); status `in_stock` (Em estoque), `active` (Ativo), `suspended` (Suspenso), `cancelled` (Cancelado), `lost` (Extraviado), `discarded` (Inutilizado). **Vigente** = `active` ou `suspended`; no máximo **um cartão vigente por titular** (índice único parcial no PostgreSQL). Cartões `cancelled`/`lost`/`discarded` **nunca voltam ao estoque**.
- **Segunda via:** substituição do cartão vigente por outro do estoque; o antigo vai para `lost` ou `discarded` e o novo **herda o status** do anterior.
- **Mensalidade (Invoice):** uma por titular por mês de referência (`reference_month` sempre dia 1; unique `customer_id + reference_month`); valor **congelado** na geração com o preço do plano vigente; status `open`, `overdue`, `paid`, `cancelled`. **Dias em atraso** = hoje − `due_date` (calculado).
- **Pagamento (Payment):** baixa de uma mensalidade (1:1), com `receipt_number` sequencial único, forma (`cash`, `pix`, `card`, `boleto`) e data. O **valor pago deve ser ≥ valor da fatura** (diferença a maior = juros/acréscimo; desconto não é permitido).
- **Estorno:** cancelamento de um pagamento (somente Admin); a fatura volta a `open`/`overdue` e a receita vinculada é cancelada; nada é apagado.
- **Suspensão automática:** cartão `active` com fatura `overdue` há **≥ `suspend_after_days`** dias (padrão 30) vai para `suspended`; volta a `active` ao pagar, se não restar fatura com atraso ≥ esse limite.
- **Lançamento financeiro (Financial Entry):** receita ou despesa com categoria, valor, vencimento, status `pending`/`paid`/`cancelled` e `paid_at`. Receitas de mensalidade são criadas automaticamente pelo pagamento e são **somente leitura**.
- **Categoria financeira:** classifica lançamentos por tipo; categorias de sistema (ex.: "Mensalidades") não podem ser excluídas.
- **Configurações da clínica (ClinicSettings):** nome/CNPJ/endereço/telefone da clínica, `default_due_day` (padrão 10), `suspend_after_days` (padrão 30), `invoice_generation_day`, `min_card_stock` (alerta de estoque baixo), `card_number_pattern` (regex opcional).
- **Vidas atendidas:** titulares ativos + dependentes ativos de cartões ativos.
- **Índice de inadimplência:** titulares com ao menos uma fatura `overdue` ÷ titulares com cartão vigente (%).
- **Dinheiro e formatos:** valores sempre `decimal(12,2)` (nunca float), exibidos como `R$ 1.234,56`; datas `d/m/Y`; CPF mascarado nas listagens (`***.456.789-**`) e para o atendente.

## Tech Stack

| Camada | Tecnologia | Evidência |
|---|---|---|
| Linguagem | PHP 8.5 (`composer.json` exige `^8.3`; Sail `sail-8.5/app`; CI `php-version: '8.5'`) | OBSERVED |
| Framework | Laravel 13.34.0 | OBSERVED |
| UI reativa | Livewire 4.4.7 (componentes de página inteira, Form Objects) + Alpine.js (embutido no Livewire) | OBSERVED (Livewire) / INFERRED (Alpine) |
| Otimização Blade | livewire/blaze 1.0.19 | OBSERVED |
| CSS / build | Tailwind CSS 4.3.3, Vite 8.3.2, vite-plus 0.3.0, laravel-vite-plugin 3.x | OBSERVED |
| Componentes de UI | Componentes Blade próprios em `resources/views/components/ui/` (sem Filament, sem Flux) | INFERRED (spec de referência) |
| Autenticação | Laravel Fortify (backend headless) com telas próprias em Livewire; base é o *blank* Livewire starter kit, sem auth | INFERRED (decisão do desenvolvedor; não instalado) |
| Banco de dados | PostgreSQL 18 (`postgres:18-alpine` no Sail) em dev, testes e CI; `.env.example` e workflow hoje apontam SQLite e serão ajustados | OBSERVED (compose) / INFERRED (decisão) |
| Permissões | spatie/laravel-permission (perfis admin, financeiro, atendente; `Gate::before` para admin) | INFERRED (aprovado; não instalado) |
| Auditoria | spatie/laravel-activitylog | INFERRED (aprovado; não instalado) |
| Configurações | spatie/laravel-settings (`ClinicSettings`) | INFERRED (aprovado; não instalado) |
| PDF | barryvdh/laravel-dompdf (recibos e relatórios, gerados sob demanda) | INFERRED (aprovado; não instalado) |
| Excel | maatwebsite/excel (exports, grandes via fila) | INFERRED (aprovado; não instalado) |
| Localização | lucascudo/laravel-pt-br-localization (ou equivalente); `APP_LOCALE=pt_BR`, timezone `America/Sao_Paulo` (hoje `en` / `UTC`) | INFERRED (aprovado; não configurado) |
| Fila / agendamento | `QUEUE_CONNECTION=database`; scheduler para `invoices:mark-overdue`, `cards:suspend-delinquent`, `invoices:generate` | OBSERVED (fila) / INFERRED (comandos) |
| Integrações | ViaCEP (autopreenchimento de endereço via `fetch` no Alpine); link `wa.me` para WhatsApp | INFERRED (spec de referência) |
| Gráficos | Chart.js integrado via Alpine (painel e fluxo de caixa) | INFERRED (decisão do desenvolvedor; não instalado) |
| Testes | Pest 5.3.0 + pest-plugin-laravel (PHPUnit por baixo) | OBSERVED |
| Qualidade | Laravel Pint 1.32.1, Larastan 3.12.2 (`composer test` = pint --test + phpstan + testes) | OBSERVED |
| Ambiente dev | Laravel Sail 1.68.0 (Docker Compose: `laravel.test` + `pgsql`); Laravel Boost 2.10.1 (MCP) | OBSERVED |
| CI | GitHub Actions `.github/workflows/tests.yml` (`composer setup` + `composer ci:check`) | OBSERVED |
| Deploy | Ubuntu Server: Nginx + PHP-FPM + PostgreSQL, Supervisor (`queue:work`), cron (`schedule:run`), Certbot, backup diário do banco fora do servidor (retenção 30 dias) | INFERRED (decisão do desenvolvedor) |

## Core Workflows

### 1. Login e roteamento por perfil

1. Usuário entra com e-mail e senha (Fortify; rate limit no login).
2. Usuário com `is_active = false` é **bloqueado** no login.
3. Após autenticar, `/` redireciona: **Atendente → `/consulta`**; Admin e Financeiro → `/painel`.
4. A sidebar mostra apenas itens permitidos (`@can`); o atendente vê só **Consulta rápida** e **Nova venda**.
5. Acesso negado a qualquer rota ou ação Livewire retorna **403** com página amigável.

### 2. Cadastro de lote de cartões (estoque)

Permissão: `cards.stock.manage`.

1. O operador informa descrição, gráfica (opcional) e data de recebimento.
2. Escolhe o modo:
   - **Faixa:** prefixo opcional + número inicial + final + quantidade de dígitos (ex.: `000001` a `000500`).
   - **Lista/CSV:** quando a numeração não é sequencial.
3. O sistema mostra uma **prévia**: números válidos e números **ignorados** (duplicados já existentes ou fora de `card_number_pattern`), com o motivo.
4. Ao confirmar, `RegisterCardBatch` cria o lote e os cartões com status `in_stock` e `customer_id` nulo, gravando um `card_status_logs` de entrada para cada cartão.
5. Um cartão danificado no estoque pode ser marcado `discarded` com motivo (`DiscardCard`).
6. O painel do estoque alerta quando `in_stock < min_card_stock`.

### 3. Nova venda (cadastro do titular + vínculo do cartão)

Permissão: `sales.create` (atendente, financeiro, admin). Tudo acontece em **uma transação**.

1. **Cartão:** o atendente digita ou lê (leitor de código de barras/QR funcionando como teclado) o número do cartão. Validação em tempo real:
   - "Disponível" → `in_stock`;
   - "Já vinculado" (sem expor o nome do outro cliente ao atendente; financeiro/admin veem "Já vinculado a Fulano");
   - "Extraviado/Inutilizado";
   - "Não cadastrado no estoque".
   Só cartões `in_stock` são aceitos.
2. **Dados pessoais:** nome, CPF (validado pela regra `Cpf`, normalizado para dígitos, único entre titulares), data de nascimento.
3. **Contato e endereço:** celular/WhatsApp obrigatório, telefone secundário e e-mail opcionais; CEP preenche o endereço via ViaCEP.
4. **Plano e vencimento:** plano ativo e `due_day` (1–28; padrão `default_due_day`).
5. **Dependentes** (opcional, na mesma tela): nome, CPF opcional, nascimento, parentesco; limitado a `max_dependents` do plano.
6. Ao salvar:
   - cria o titular (`joined_at` = hoje) e o `plan_changes` de adesão (`from_plan_id` nulo);
   - `AssignCard`: cartão recebe `customer_id`, status `active`, `assigned_at` = hoje, com log;
   - gera a **primeira mensalidade com valor cheio** do plano, vencendo no **próximo `due_day`**.
7. A tela volta para a consulta rápida exibindo o cliente recém-cadastrado. O atendente **não vê** a mensalidade gerada nem pode editar o cadastro depois (correções ficam com `customers.update`).

### 4. Consulta rápida

Permissão: `cards.check`.

1. Campo único com foco automático; busca com debounce de 300 ms por **nome** (parcial, `ILIKE`/`pg_trgm`), **CPF**, **número do cartão** ou **identificação de dependente** (`000123-02`).
2. Busca por **número de cartão antigo** encontra o cliente e avisa que aquele cartão foi substituído.
3. Resultado em card grande: nome, plano, **badge de status do cartão** e dependentes.
4. Com `billing.view`: próxima mensalidade, pendências e valores.
5. Sem `billing.view` (atendente): CPF mascarado, nenhum valor; se suspenso, apenas "Cartão suspenso — encaminhar ao financeiro".

### 5. Segunda via do cartão

Permissão: `cards.manage`.

1. Na ficha do cliente, o operador escolhe o motivo: perda/roubo → `lost`, dano → `discarded`.
2. Informa o número de um novo cartão `in_stock`.
3. `ReplaceCard` (transação): cartão antigo vai para `lost`/`discarded`; o novo é vinculado **herdando o status** do anterior (suspenso continua suspenso); logs nos dois cartões.
4. As identificações dos dependentes passam a usar o novo número automaticamente. O cartão antigo permanece no histórico e nunca é reutilizado.

### 6. Dependentes e troca de plano

Permissões: `customers.update` (dependentes), `cards.manage` (troca de plano).

1. **Adicionar dependente:** permitido apenas se `dependentes ativos < plano.max_dependents`; senão, mensagem clara de limite atingido. Recebe o próximo `sequence` (nunca reaproveitado).
2. **Inativar dependente:** libera vaga no limite; mantém `sequence` e histórico.
3. **Troca de plano** (`ChangePlan`): bloqueia se os dependentes ativos não couberem no novo plano; grava `plan_changes` (planos e preços de/para, motivo, usuário). O novo valor vale **a partir da próxima mensalidade gerada**; faturas já geradas não mudam.

### 7. Geração mensal de mensalidades

Agendado diariamente às 01:00 (`invoices:generate {--month=}`).

1. Executa de fato quando `hoje.day == invoice_generation_day` (gera o **mês seguinte**) ou quando `--month` é informado manualmente.
2. Para cada titular com cartão `active` ou `suspended`, cria a fatura do mês se ainda não existir (unique key garante **idempotência**):
   - `amount` = preço atual do plano;
   - `due_date` = `reference_month` com o dia `customer.due_day`;
   - `plan_id` = plano vigente.
3. Cartões `cancelled` não geram mensalidades.
4. Registra no activity log a quantidade de faturas criadas.

### 8. Atraso e suspensão automática

1. **00:10 — `invoices:mark-overdue`:** faturas `open` com `due_date < hoje` → `overdue`.
2. **00:20 — `cards:suspend-delinquent`:** cartões `active` com alguma fatura `overdue` com atraso **≥ `suspend_after_days`** → `suspended`, com log de motivo (ex.: "Suspensão automática: 32 dias em atraso", `user_id` nulo = sistema).
3. Ambos registram no activity log a quantidade de registros afetados.
4. Tela **Inadimplentes** (`billing.view`): titulares com faturas em atraso — nome, telefone (link `https://wa.me/55...`), qtd. de faturas, total, maior atraso, status do cartão — ordenados pelo maior atraso, exportável em PDF/Excel.

### 9. Registro de pagamento e recibo

Permissão: `billing.pay`. `RegisterPayment` roda em transação com `lockForUpdate` na fatura.

1. O operador abre o modal de pagamento: data, valor (pré-preenchido com o da fatura), forma, observação.
2. Validações: fatura não pode estar `paid` ("Esta mensalidade já foi paga.") nem `cancelled`; **valor pago ≥ valor da fatura**.
3. Efeitos:
   - cria `payments` com o próximo `receipt_number` (sequence do PostgreSQL, seguro para concorrência);
   - fatura → `paid`, `paid_at` preenchido;
   - cria `financial_entries` de receita, categoria "Mensalidades", status `paid`, vinculada ao pagamento (somente leitura);
   - se o cartão está `suspended` e não resta fatura `overdue` com atraso ≥ `suspend_after_days`, reativa (`ReactivateCard`, motivo "Reativação por pagamento").
4. Toast de sucesso + botão **Imprimir recibo**: PDF gerado sob demanda (não armazenado) com dados da clínica, titular, cartão, referência, valor, forma e data.

```text
GET /recibos/{payment}  →  application/pdf  (stream "recibo-{receipt_number}.pdf")
```

### 10. Estorno de pagamento

Permissão: `billing.refund` (somente Admin).

1. Admin estorna um pagamento.
2. A fatura volta a `open` (se `due_date >= hoje`) ou `overdue` (se vencida); `paid_at` é limpo.
3. A receita vinculada vai para `cancelled`. Nenhum registro é apagado; tudo fica no activity log.

### 11. Suspensão, reativação e cancelamento manuais do cartão

Permissão: `cards.manage`.

1. **Suspender / reativar** manualmente, com motivo, gravando `card_status_logs`.
2. **Cancelar:** o modal pergunta o que fazer com as faturas pendentes (`open`/`overdue`) do titular:
   - **cancelar** as faturas pendentes (→ `cancelled`), ou
   - **manter** as faturas (continuam cobráveis e aparecem em Inadimplentes).
3. O cancelamento é **definitivo**: cartão cancelado não gera novas mensalidades, **não pode ser reativado** (nem manualmente, nem por Admin) e **não volta ao estoque**. O titular pode voltar ao programa pelo **reingresso** (workflow 16), com outro cartão.

### 12. Lançamentos financeiros, contas a pagar/receber e baixas

Permissões: `finance.view`, `finance.manage`.

1. **Lançamentos manuais:** receita ou despesa com categoria, descrição, valor, vencimento, fornecedor/pagador, observação; podem ser criados já baixados.
2. Lançamentos automáticos (mensalidades) exibem cadeado e não são editáveis.
3. **Contas a pagar** = despesas `pending`, agrupadas em vencidas / vencendo em 7 dias / futuras.
4. **Contas a receber** = receitas manuais `pending` + mensalidades `open`/`overdue` (somente leitura, com link para o faturamento).
5. **Baixa:** informa `paid_at` e forma de pagamento → `paid`.
6. **Categorias:** CRUD; categorias de sistema não podem ser excluídas.

### 13. Fluxo de caixa

Regime de caixa, baseado em `paid_at`.

1. **Saldo inicial** = receitas pagas − despesas pagas antes do início do período.
2. Visão **diária** (dias do mês) ou **mensal** (meses do ano): entradas, saídas, saldo do período e **saldo acumulado**, com gráfico de barras.
3. Linha opcional de **previsto** usando `due_date` dos itens pendentes.

### 14. Painel e relatórios

Permissões: `dashboard.view`, `reports.view`.

1. **Painel:** cartões ativos, cartões em estoque (alerta abaixo de `min_card_stock`), vidas atendidas, receita e despesa do mês, valor em aberto, valor em atraso, índice de inadimplência; gráfico receita × despesa dos últimos 12 meses; últimos pagamentos; 5 maiores atrasos.
2. **Relatórios** (PDF e Excel, por período): clientes e dependentes; mensalidades por período/status; inadimplência; pagamentos recebidos por forma; lançamentos por categoria; fluxo de caixa. Cada relatório usa uma consulta compartilhada entre o export Excel e a view PDF; exports grandes podem rodar em fila.

### 15. Administração: usuários e configurações

1. **Usuários** (`users.manage`, somente Admin): criar, definir perfil, ativar/desativar (`is_active`), redefinir senha.
2. **Configurações** (`settings.manage`, somente Admin): dados da clínica, vencimento padrão, dias para suspensão, dia de geração, estoque mínimo, padrão do número do cartão.
3. **LGPD:** auditoria (activity log) nos models principais registrando apenas campos alterados; soft delete em cadastros; pagamentos e lançamentos nunca são excluídos fisicamente; exportação dos dados do titular na ficha; HTTPS e `SESSION_SECURE_COOKIE=true`.

### 16. Reingresso de titular cancelado

Permissão: `cards.manage`. Tudo acontece em **uma transação**.

1. Disponível na ficha apenas para titular **sem cartão vigente** cujo último cartão está `cancelled`.
2. O operador informa um novo cartão `in_stock` (mesma validação em tempo real da venda), um plano **ativo** e o `due_day`.
3. O plano escolhido precisa comportar os dependentes ativos; senão, a ação é bloqueada informando quantos inativar.
4. Efeitos (`ReenrollCustomer`):
   - `AssignCard`: novo cartão `active`, `assigned_at` = hoje, com log;
   - `plan_changes` com motivo "Reingresso" (plano e preço de/para);
   - nova mensalidade com **valor cheio**, vencendo no próximo `due_day` (mesma regra da adesão).
5. O cartão cancelado continua no histórico (e na busca por cartão antigo); faturas mantidas no cancelamento continuam cobráveis.
6. Os dependentes mantêm `sequence` e passam a usar a identificação do novo cartão.

## Open Questions

- **Máscara de input:** confirmar se o plugin `@alpinejs/mask` continua embutido no Livewire 4 (a spec cita Livewire 3) ou se deve ser adicionado.
- **Dados da clínica:** nome, CNPJ, endereço e telefone para recibos e configurações iniciais.
