# Cartão Fidelidade — User Stories

<!-- inputs: project-description.md@sha256:a685bf13b06b -->

## Overview

Sistema web para **uma única clínica médica** administrar seu programa de cartão fidelidade: venda de planos mensais a titulares e dependentes, vínculo de cartões físicos pré-impressos retirados do estoque, cobrança de mensalidades com suspensão automática por inadimplência, e o financeiro da clínica (lançamentos, contas, fluxo de caixa, painel e relatórios). A interface é web, responsiva e em pt-BR.

O acesso é dividido em três perfis com permissões definidas na especificação de referência (§10.1), verificadas em rota, componente Livewire, Policy e view. Rotinas diárias automáticas (geração de mensalidades, atraso e suspensão) rodam pelo agendador.

**User Types:**
- **Admin** - acesso total, incluindo planos, estorno de pagamentos, configurações da clínica e usuários.
- **Financeiro** - gestão de clientes e cartões, estoque, faturamento, financeiro, painel e relatórios; não gerencia planos, configurações, usuários nem estorna pagamentos.
- **Atendente** - apenas realiza vendas (cadastro de titular + vínculo de cartão) e consulta o status do cartão; não vê valores, mensalidades nem pendências.
- **Sistema (agendador)** - ator não humano que executa as rotinas diárias de faturamento e suspensão.

---

## 1. Acesso e usuários

### US-1.1: Entrar no sistema
**As a** Admin, Financeiro ou Atendente
**I want to** entrar com e-mail e senha
**So that** eu acesse as funções do meu perfil com segurança

**Acceptance Criteria:**
- [ ] Login com e-mail e senha via Laravel Fortify, com telas próprias em Livewire.
- [ ] Credenciais inválidas exibem mensagem genérica em pt-BR, sem revelar se o e-mail existe.
- [ ] Rate limit no login: após 5 tentativas falhas por minuto (e-mail + IP), novas tentativas são bloqueadas até o fim da janela.
- [ ] Usuário com `is_active = false` não consegue entrar, mesmo com senha correta, e vê mensagem de conta desativada.
- [ ] Logout encerra a sessão e redireciona para o login.
- [ ] Todas as rotas da aplicação exigem autenticação; visitante é redirecionado ao login.

**Expected Result:** Apenas usuários ativos com credenciais válidas acessam o sistema, com proteção contra força bruta.

---

### US-1.2: Ser direcionado à tela certa do meu perfil
**As a** Admin, Financeiro ou Atendente
**I want to** cair na tela inicial e no menu adequados ao meu perfil
**So that** eu veja apenas o que posso usar

**Acceptance Criteria:**
- [ ] Após o login, `/` redireciona o Atendente para `/consulta` e Admin/Financeiro para `/painel`.
- [ ] A sidebar exibe apenas os itens permitidos ao perfil (`@can`).
- [ ] O Atendente vê somente **Consulta rápida** e **Nova venda** no menu.
- [ ] Planos, Configurações e Usuários aparecem apenas para o Admin.

**Expected Result:** Cada perfil começa na sua tela principal e navega por um menu sem itens inacessíveis.

---

### US-1.3: Ter acesso negado ao que não é do meu perfil
**As a** Atendente ou Financeiro
**I want to** ser bloqueado ao tentar acessar telas ou ações fora do meu perfil
**So that** dados financeiros e administrativos fiquem protegidos mesmo se eu digitar a URL

**Acceptance Criteria:**
- [ ] Toda rota tem middleware `can:<permissão>` conforme a matriz §10.1; acesso negado retorna **403** com página amigável em pt-BR.
- [ ] O Atendente recebe 403 em `/painel`, `/clientes`, `/cartoes`, `/faturamento`, `/financeiro/*`, `/relatorios`, `/configuracoes` e `/usuarios`.
- [ ] Componentes Livewire chamam `authorize()` no `mount()` **e** em cada método público que altera dados; chamar um método protegido diretamente (ex.: `save` do modal de pagamento) sem permissão resulta em 403.
- [ ] O Admin recebe todas as permissões via `Gate::before`.
- [ ] Policies `CustomerPolicy`, `CardPolicy`, `InvoicePolicy`, `PaymentPolicy` e `FinancialEntryPolicy` mapeiam cada ação à permissão correspondente.

**Expected Result:** Nenhuma tela ou ação fora do perfil é acessível, seja pelo menu, pela URL ou por chamada direta ao Livewire.

---

### US-1.4: Gerenciar usuários
**As a** Admin
**I want to** criar usuários, definir perfil, ativar/desativar e redefinir senhas
**So that** a equipe da clínica tenha o acesso correto

**Acceptance Criteria:**
- [ ] Tela `Usuários` acessível apenas com `users.manage`.
- [ ] Criar usuário com nome, e-mail (único), senha e perfil (admin, financeiro ou atendente).
- [ ] Alterar o perfil de um usuário existente.
- [ ] Ativar/desativar usuário (`is_active`); usuário desativado é bloqueado no próximo login.
- [ ] Redefinir a senha de um usuário.
- [ ] O Admin não consegue desativar a própria conta.

**Expected Result:** O Admin controla quem acessa o sistema e com qual perfil.

---

## 2. Estoque de cartões

### US-2.1: Cadastrar lote de cartões por faixa
**As a** Financeiro ou Admin
**I want to** cadastrar um lote informando uma faixa de números
**So that** os cartões já impressos pela gráfica fiquem disponíveis para venda

**Acceptance Criteria:**
- [ ] Formulário com descrição, gráfica (opcional), data de recebimento, prefixo opcional, número inicial, número final e quantidade de dígitos (ex.: `000001` a `000500`).
- [ ] Antes de confirmar, uma **prévia** lista os números válidos e os **ignorados** com o motivo: duplicado (já existe) ou fora de `card_number_pattern`.
- [ ] Ao confirmar, o lote é criado e cada cartão válido entra com status `in_stock` e `customer_id` nulo, com um registro em `card_status_logs`.
- [ ] Número inicial maior que o final é rejeitado com mensagem clara.
- [ ] O sistema **não gera** números de cartão além dos informados pela faixa.
- [ ] Exige permissão `cards.stock.manage`.

**Expected Result:** Os cartões da faixa estão em estoque, prontos para vínculo, e os números rejeitados foram informados ao usuário.

---

### US-2.2: Cadastrar lote de cartões por lista ou CSV
**As a** Financeiro ou Admin
**I want to** cadastrar um lote a partir de uma lista ou arquivo CSV
**So that** eu registre cartões com numeração não sequencial

**Acceptance Criteria:**
- [ ] Aceita números colados (um por linha) ou upload de CSV com uma coluna de números.
- [ ] Mesmas regras da faixa: prévia com válidos e ignorados (duplicados, repetidos na própria lista, fora do padrão), confirmação, status `in_stock` e log por cartão.
- [ ] O número é armazenado exatamente como impresso no cartão (sem normalização além de remover espaços nas pontas).

**Expected Result:** Cartões com numeração arbitrária entram no estoque com a mesma validação da faixa.

---

### US-2.3: Acompanhar o estoque e os lotes
**As a** Financeiro ou Admin
**I want to** ver os cartões e lotes com seus status
**So that** eu saiba quando pedir um novo lote

**Acceptance Criteria:**
- [ ] Cards de resumo: em estoque, vinculados (ativos + suspensos) e extraviados/inutilizados.
- [ ] Alerta visual quando `in_stock < min_card_stock` das configurações.
- [ ] Aba **Cartões**: tabela com número, lote, status (badge), cliente vinculado e data; filtros por status, lote e número.
- [ ] Aba **Lotes**: cada lote com faixa, data de recebimento, gráfica e uso (vinculados, disponíveis, inutilizados) com barra de progresso.
- [ ] Exige permissão `cards.stock.view`.

**Expected Result:** O estoque disponível e o consumo de cada lote ficam visíveis, com alerta de estoque baixo.

---

### US-2.4: Inutilizar cartão danificado no estoque
**As a** Financeiro ou Admin
**I want to** marcar um cartão em estoque como inutilizado
**So that** ele nunca seja entregue a um cliente

**Acceptance Criteria:**
- [ ] Ação disponível apenas para cartões `in_stock`.
- [ ] Motivo obrigatório; status vai para `discarded` com registro em `card_status_logs`.
- [ ] Cartão `discarded` nunca volta ao estoque nem pode ser vinculado.

**Expected Result:** Cartões danificados saem do estoque disponível com motivo registrado.

---

## 3. Planos

### US-3.1: Gerenciar planos
**As a** Admin
**I want to** criar e editar planos com valor e limite de dependentes
**So that** as ofertas da clínica fiquem disponíveis para venda

**Acceptance Criteria:**
- [ ] CRUD em modal: nome, descrição, preço mensal (`decimal(12,2)`), `max_dependents` (0 = sem dependentes) e ativo/inativo.
- [ ] Plano inativo não aparece para novas adesões nem para troca de plano, mas continua válido para quem já o tem.
- [ ] Alterar o preço não muda mensalidades já geradas; vale para as próximas gerações.
- [ ] Cada plano exibe a quantidade de titulares ativos.
- [ ] Exige permissão `plans.manage` (somente Admin).

**Expected Result:** O catálogo de planos é mantido pelo Admin sem afetar faturas já emitidas.

---

## 4. Venda e cadastro de clientes

### US-4.1: Realizar uma nova venda
**As a** Atendente, Financeiro ou Admin
**I want to** cadastrar um titular e vincular a ele um cartão do estoque
**So that** o cliente saia da clínica com o cartão ativo

**Acceptance Criteria:**
- [ ] Formulário com seções: Cartão, Dados pessoais (nome, CPF, nascimento), Contato (celular obrigatório, telefone secundário e e-mail opcionais), Endereço, Plano (somente ativos) e Vencimento (`due_day` 1–28, padrão `default_due_day`).
- [ ] CPF validado pela regra `Cpf` (dígitos verificadores; rejeita sequências repetidas), normalizado para 11 dígitos e único entre titulares.
- [ ] CEP preenche o endereço automaticamente via ViaCEP; falha da consulta permite preenchimento manual.
- [ ] Cadastro do titular, vínculo do cartão (`AssignCard`) e registro de adesão em `plan_changes` acontecem em **uma transação**; falha em qualquer parte desfaz tudo; não existe titular sem cartão.
- [ ] Ao vincular: cartão recebe `customer_id`, status `active`, `assigned_at` = hoje e registro em `card_status_logs`.
- [ ] Ao concluir, a tela volta à consulta rápida exibindo o cliente recém-cadastrado.
- [ ] Exige permissão `sales.create`.

**Expected Result:** Titular cadastrado, cartão ativo vinculado e adesão registrada atomicamente.

---

### US-4.2: Validar o cartão em tempo real durante a venda
**As a** Atendente, Financeiro ou Admin
**I want to** digitar ou ler o número do cartão e saber na hora se ele pode ser usado
**So that** eu não entregue um cartão errado ao cliente

**Acceptance Criteria:**
- [ ] O campo aceita digitação ou leitor de código de barras/QR (que funciona como teclado).
- [ ] Exibe em tempo real: "Disponível" (`in_stock`), "Já vinculado", "Extraviado/Inutilizado" ou "Não cadastrado no estoque".
- [ ] Para o Atendente, "Já vinculado" **não** exibe o nome do outro cliente; Financeiro/Admin veem "Já vinculado a Fulano".
- [ ] Só é possível concluir a venda com cartão `in_stock`; a validação é repetida no servidor ao salvar.

**Expected Result:** Apenas cartões disponíveis em estoque podem ser vinculados, sem expor dados de terceiros ao atendente.

---

### US-4.3: Incluir dependentes já na venda
**As a** Atendente, Financeiro ou Admin
**I want to** adicionar dependentes na mesma tela do cadastro do titular
**So that** a família inteira seja cadastrada de uma vez

**Acceptance Criteria:**
- [ ] Repeater com nome, CPF (opcional; validado e único entre dependentes quando informado), data de nascimento e parentesco (Cônjuge, Filho(a), Pai, Mãe, Irmão(ã), Outro).
- [ ] Quantidade limitada a `max_dependents` do plano escolhido; ao trocar o plano no formulário, o limite é revalidado.
- [ ] Cada dependente recebe `sequence` 1, 2, 3… e a identificação `{numero_cartao}-{sequence:02}` (ex.: `000123-02`).

**Expected Result:** Titular e dependentes ficam cadastrados juntos, respeitando o limite do plano.

---

### US-4.4: Gerar a primeira mensalidade na adesão
**As a** Financeiro
**I want to** que a primeira mensalidade seja gerada automaticamente na venda
**So that** a cobrança comece sem trabalho manual

**Acceptance Criteria:**
- [ ] Na conclusão da venda, é criada uma mensalidade com o **valor cheio** do plano, vencendo no **próximo** `due_day` a partir da data de adesão.
- [ ] `reference_month` é o dia 1 do mês do vencimento; `amount` e `plan_id` ficam congelados.
- [ ] A geração faz parte da mesma transação da venda.
- [ ] O Atendente não visualiza a mensalidade gerada.

**Expected Result:** Todo novo titular já sai com sua primeira mensalidade em aberto.

---

### US-4.5: Listar e filtrar clientes
**As a** Financeiro ou Admin
**I want to** ver a lista de titulares com filtros
**So that** eu encontre rapidamente quem preciso atender

**Acceptance Criteria:**
- [ ] Tabela paginada: cartão, nome, CPF mascarado (`***.456.789-**`), plano, nº de dependentes ativos e status do cartão (badge).
- [ ] Filtros por status, plano e busca (nome, CPF ou cartão), mantidos na URL (`#[Url]`) para compartilhar e recarregar.
- [ ] Botão "Novo cliente" leva ao formulário de venda.
- [ ] Clicar na linha abre a ficha do cliente.
- [ ] Exige permissão `customers.view`.

**Expected Result:** O Financeiro localiza qualquer titular por status, plano ou busca.

---

### US-4.6: Editar o cadastro de um cliente
**As a** Financeiro ou Admin
**I want to** corrigir os dados do titular após a venda
**So that** o cadastro fique correto

**Acceptance Criteria:**
- [ ] Edição de dados pessoais, contato, endereço e `due_day` com as mesmas validações da venda.
- [ ] Mudança de `due_day` vale para as próximas mensalidades geradas.
- [ ] O Atendente não tem acesso à edição (`customers.update`).

**Expected Result:** Correções cadastrais são feitas pelo Financeiro/Admin com as mesmas regras da venda.

---

### US-4.7: Consultar a ficha do cliente
**As a** Financeiro ou Admin
**I want to** ver tudo sobre um titular em uma única tela com abas
**So that** eu atenda o cliente sem procurar em vários lugares

**Acceptance Criteria:**
- [ ] Cabeçalho com nome, status do cartão, data de adesão e ações (segunda via, trocar plano, registrar pagamento).
- [ ] Aba **Resumo**: cartão vigente (número, desde quando, plano, vencimento, uso de dependentes), cartões anteriores com motivo e data, ações de suspender/reativar/cancelar conforme o status, e dados do titular (CPF completo).
- [ ] Abas **Dependentes**, **Mensalidades** (com ação de baixa e recibo), **Histórico** e **Observações**.
- [ ] Exige permissão `customers.view`.

**Expected Result:** A ficha concentra cadastro, cartão, dependentes, mensalidades, histórico e notas do titular.

---

### US-4.8: Ver o histórico do cliente
**As a** Financeiro ou Admin
**I want to** ver uma linha do tempo dos eventos do titular
**So that** eu entenda o que aconteceu com o cliente

**Acceptance Criteria:**
- [ ] Timeline em ordem cronológica com adesão, trocas de plano, mudanças de status do cartão (incluindo segunda via e suspensões automáticas), pagamentos e observações.
- [ ] Cada evento mostra data, título, descrição e usuário responsável (ou "sistema").

**Expected Result:** Todo o histórico relevante do titular está visível em uma linha do tempo.

---

### US-4.9: Registrar observações sobre o cliente
**As a** Financeiro ou Admin
**I want to** adicionar notas à ficha do cliente
**So that** a equipe saiba de combinados e preferências

**Acceptance Criteria:**
- [ ] Campo de texto + botão "Adicionar"; nota vazia é rejeitada.
- [ ] Cada nota registra autor e data e aparece no topo da lista e na timeline.

**Expected Result:** Observações ficam registradas na ficha com autor e data.

---

## 5. Consulta rápida

### US-5.1: Encontrar um cliente rapidamente
**As a** Atendente, Financeiro ou Admin
**I want to** buscar por nome, CPF, número do cartão ou identificação do dependente em um campo único
**So that** eu confirme o status do cartão no balcão em segundos

**Acceptance Criteria:**
- [ ] Campo único com foco automático e busca com debounce de 300 ms; atalho **F2** abre a consulta de qualquer tela.
- [ ] Busca por nome parcial (sem diferenciar maiúsculas/acentos, via `ILIKE`/`pg_trgm`), CPF (com ou sem máscara), número do cartão vigente e identificação de dependente (`000123-02`).
- [ ] Resultado em card grande com nome, plano, **status do cartão bem visível** e dependentes com suas identificações.
- [ ] Busca por dependente destaca qual dependente foi encontrado.
- [ ] Sem resultados: mensagem "Nenhum cliente encontrado" com orientação.
- [ ] Exige permissão `cards.check`.

**Expected Result:** O status do cartão de qualquer titular ou dependente é encontrado a partir de um único campo.

---

### US-5.2: Encontrar o cliente por um cartão antigo
**As a** Atendente, Financeiro ou Admin
**I want to** que a busca encontre o cliente também pelo número de um cartão substituído
**So that** eu identifique quem apresentou um cartão que não vale mais

**Acceptance Criteria:**
- [ ] Buscar o número de um cartão `lost`/`discarded` que pertenceu ao cliente retorna esse cliente.
- [ ] O resultado exibe aviso: "O cartão X foi substituído. Cartão atual: Y."

**Expected Result:** Cartões antigos levam ao cliente atual com aviso de substituição.

---

### US-5.3: Ver resultado restrito como Atendente
**As a** Atendente
**I want to** ver apenas o status do cartão, sem valores
**So that** eu atenda sem acessar informações financeiras

**Acceptance Criteria:**
- [ ] Com `billing.view` (Financeiro/Admin): o resultado mostra próxima mensalidade, pendências (quantidade e valor em atraso) e botão "Registrar pagamento".
- [ ] Sem `billing.view` (Atendente): nenhum valor, mensalidade ou pendência é exibido; CPF aparece mascarado.
- [ ] Para o Atendente, cartão suspenso mostra apenas "Cartão suspenso — encaminhar ao financeiro".
- [ ] O Atendente não vê o botão "Abrir ficha" (`customers.view`).

**Expected Result:** O Atendente confirma o status do cartão sem ver dados financeiros.

---

## 6. Gestão do cartão

### US-6.1: Emitir segunda via do cartão
**As a** Financeiro ou Admin
**I want to** substituir o cartão de um cliente por outro do estoque
**So that** o cliente continue atendido após perda, roubo ou dano

**Acceptance Criteria:**
- [ ] Modal com motivo (perda/roubo → `lost`; dano → `discarded`) e número do novo cartão com validação em tempo real (mesmas mensagens da venda).
- [ ] Só aceita novo cartão `in_stock`; botão de confirmar desabilitado até o cartão ser válido.
- [ ] Em uma transação: cartão antigo vai para `lost`/`discarded`, o novo é vinculado **herdando o status** do anterior (suspenso continua suspenso), com logs nos dois cartões.
- [ ] As identificações dos dependentes passam a usar o novo número (calculadas, não armazenadas).
- [ ] O cartão antigo aparece em "Cartões anteriores" na ficha e nunca pode ser reutilizado.
- [ ] Exige permissão `cards.manage`.

**Expected Result:** O cliente tem um novo cartão vigente com o mesmo status e o antigo fica inutilizável no histórico.

---

### US-6.2: Suspender e reativar o cartão manualmente
**As a** Financeiro ou Admin
**I want to** suspender ou reativar um cartão informando o motivo
**So that** eu trate casos que as regras automáticas não cobrem

**Acceptance Criteria:**
- [ ] "Suspender" disponível apenas para cartão `active`; "Reativar" apenas para `suspended`.
- [ ] Motivo obrigatório; mudança registrada em `card_status_logs` com o usuário.
- [ ] Dependentes herdam o status do cartão do titular.
- [ ] Exige permissão `cards.manage`.

**Expected Result:** O status do cartão muda manualmente com motivo e autor registrados.

---

### US-6.3: Cancelar o cartão
**As a** Financeiro ou Admin
**I want to** cancelar o cartão de um titular escolhendo o destino das faturas pendentes
**So that** o cliente saia do programa com a cobrança resolvida

**Acceptance Criteria:**
- [ ] Ação disponível para cartão `active` ou `suspended`; motivo obrigatório.
- [ ] O modal pergunta o que fazer com as faturas `open`/`overdue`: **cancelar** (→ `cancelled`) ou **manter** (continuam cobráveis e aparecem em Inadimplentes).
- [ ] Cartão vai para `cancelled` com log; não gera novas mensalidades.
- [ ] O cancelamento é **definitivo**: cartão cancelado não pode ser reativado (nenhum perfil vê "Reativar") e **não volta ao estoque**; o titular só volta ao programa pelo reingresso (US-6.4).
- [ ] Exige permissão `cards.manage`.

**Expected Result:** O cartão é cancelado definitivamente e as faturas pendentes seguem a escolha do operador.

---

### US-6.4: Reingressar um titular cancelado
**As a** Financeiro ou Admin
**I want to** vincular um novo cartão do estoque a um titular cujo cartão foi cancelado
**So that** o cliente volte ao programa sem um novo cadastro (o CPF é único)

**Acceptance Criteria:**
- [ ] Ação "Reingressar" aparece na ficha apenas para titular **sem cartão vigente** cujo último cartão está `cancelled`.
- [ ] Novo cartão validado em tempo real como na venda; só `in_stock` é aceito.
- [ ] Plano escolhido deve estar ativo e comportar os dependentes ativos; caso contrário, a ação é bloqueada informando quantos inativar.
- [ ] Em uma transação: novo cartão `active` com log, `plan_changes` com motivo "Reingresso" e nova mensalidade com valor cheio no próximo `due_day`.
- [ ] O cartão cancelado continua no histórico e a busca por ele encontra o titular com aviso de substituição.
- [ ] Faturas mantidas no cancelamento continuam cobráveis; dependentes mantêm o `sequence` e passam a usar o novo número.
- [ ] Exige permissão `cards.manage`.

**Expected Result:** O titular volta a ter cartão ativo e cobrança, preservando todo o histórico anterior.

---

## 7. Dependentes e plano

### US-7.1: Gerenciar dependentes após a venda
**As a** Financeiro ou Admin
**I want to** adicionar, editar e inativar dependentes de um titular
**So that** a família cadastrada reflita a realidade

**Acceptance Criteria:**
- [ ] Adicionar só é permitido se `dependentes ativos < plano.max_dependents`; ao atingir o limite, mensagem clara (ex.: "O plano Familiar permite até 4 dependentes"); plano sem dependentes informa que é preciso trocar de plano.
- [ ] Novo dependente recebe o próximo `sequence`; o `sequence` **nunca é reaproveitado**, nem após inativação.
- [ ] Inativar libera vaga no limite e mantém histórico e identificação.
- [ ] Edição com as mesmas validações (CPF opcional, válido e único quando informado).
- [ ] Exige permissão `customers.update`.

**Expected Result:** Dependentes são mantidos dentro do limite do plano com identificações estáveis.

---

### US-7.2: Trocar o plano do titular
**As a** Financeiro ou Admin
**I want to** mudar o plano de um titular
**So that** ele pague o valor adequado à cobertura escolhida

**Acceptance Criteria:**
- [ ] Lista apenas planos ativos.
- [ ] Bloqueia a troca se os dependentes ativos excederem `max_dependents` do novo plano, informando quantos precisam ser inativados.
- [ ] Grava `plan_changes` com planos e preços de/para, motivo opcional e usuário.
- [ ] O novo valor vale a partir da **próxima** mensalidade gerada; faturas já geradas não mudam.
- [ ] Exige permissão `cards.manage`.

**Expected Result:** O plano é trocado com histórico e sem alterar faturas já emitidas.

---

## 8. Mensalidades e cobrança

### US-8.1: Gerar as mensalidades do mês automaticamente
**As a** Sistema (agendador)
**I want to** gerar as mensalidades do mês seguinte no dia configurado
**So that** todos os titulares vigentes sejam cobrados sem trabalho manual

**Acceptance Criteria:**
- [ ] Comando `invoices:generate {--month=}` agendado diariamente às 01:00; gera de fato quando `hoje.day == invoice_generation_day` (mês seguinte) ou quando `--month` é informado.
- [ ] Cria uma fatura por titular com cartão `active` ou `suspended`; titulares com cartão `cancelled` não são cobrados.
- [ ] `amount` = preço **atual** do plano; `due_date` = mês de referência no dia `due_day`; `plan_id` = plano vigente.
- [ ] **Idempotente**: rodar duas vezes não duplica faturas (unique `customer_id + reference_month`).
- [ ] Registra no activity log a quantidade de faturas criadas.

**Expected Result:** No dia configurado, cada titular vigente tem exatamente uma mensalidade do mês seguinte.

---

### US-8.2: Marcar mensalidades vencidas como em atraso
**As a** Sistema (agendador)
**I want to** marcar como em atraso as faturas vencidas
**So that** a inadimplência fique visível

**Acceptance Criteria:**
- [ ] Comando `invoices:mark-overdue` agendado diariamente às 00:10.
- [ ] Muda `open` → `overdue` somente quando `due_date < hoje`; fatura que vence hoje continua `open`.
- [ ] Não altera faturas `paid` nem `cancelled`.
- [ ] Dias em atraso = hoje − `due_date`, calculado (não armazenado).
- [ ] Registra no activity log a quantidade de faturas alteradas.

**Expected Result:** Toda fatura vencida e não paga aparece como em atraso no dia seguinte ao vencimento.

---

### US-8.3: Suspender automaticamente cartões de inadimplentes
**As a** Sistema (agendador)
**I want to** suspender os cartões com atraso acima do limite
**So that** o benefício só valha para quem está em dia

**Acceptance Criteria:**
- [ ] Comando `cards:suspend-delinquent` agendado diariamente às 00:20.
- [ ] Suspende cartões `active` com alguma fatura `overdue` com atraso **maior ou igual** a `suspend_after_days` (padrão 30).
- [ ] Atraso de `suspend_after_days − 1` dias não suspende.
- [ ] Registra `card_status_logs` com motivo (ex.: "Suspensão automática: 32 dias em atraso") e `user_id` nulo (sistema).
- [ ] Registra no activity log a quantidade de cartões suspensos.

**Expected Result:** Cartões de titulares com atraso igual ou maior que o limite ficam suspensos automaticamente.

---

### US-8.4: Acompanhar as mensalidades
**As a** Financeiro ou Admin
**I want to** ver e filtrar as mensalidades com um resumo dos valores
**So that** eu acompanhe o que foi pago e o que está pendente

**Acceptance Criteria:**
- [ ] Cards de resumo do período filtrado: total, pago, em aberto e em atraso (valor e quantidade).
- [ ] Tabela: cliente, cartão, referência, vencimento, valor, status (badge; em atraso mostra "Em atraso · N dias") e ação "Registrar pagamento" (faturas pagáveis) ou "Recibo" (pagas).
- [ ] Filtros por período (mês de referência ou intervalo de vencimento), status, plano e cliente, mantidos na URL.
- [ ] Exige permissão `billing.view`.

**Expected Result:** O Financeiro enxerga a situação das mensalidades de qualquer período.

---

### US-8.5: Acompanhar os inadimplentes
**As a** Financeiro ou Admin
**I want to** ver os titulares com faturas em atraso e contatá-los
**So that** eu cobre quem está devendo

**Acceptance Criteria:**
- [ ] Lista: nome, telefone com link `https://wa.me/55<dígitos>`, quantidade de faturas em atraso, valor total, maior atraso em dias e status do cartão.
- [ ] Ordenada pelo maior atraso.
- [ ] Resumo com total de titulares e valor em atraso.
- [ ] Exportação em PDF e Excel.
- [ ] Contador de inadimplentes no item do menu.
- [ ] Exige permissão `billing.view`.

**Expected Result:** O Financeiro tem a lista de cobrança priorizada e exportável.

---

## 9. Pagamentos

### US-9.1: Registrar o pagamento de uma mensalidade
**As a** Financeiro ou Admin
**I want to** dar baixa em uma mensalidade
**So that** o pagamento fique registrado e entre no caixa

**Acceptance Criteria:**
- [ ] Modal com data do pagamento, valor (pré-preenchido com o valor da fatura), forma (PIX, Dinheiro, Cartão, Boleto) e observação.
- [ ] **Valor pago menor que o valor da fatura é rejeitado**; valor maior é aceito (juros/acréscimo).
- [ ] Fatura `paid` ou `cancelled` não pode receber baixa ("Esta mensalidade já foi paga.").
- [ ] Em transação com `lockForUpdate` na fatura: cria `payments` com o próximo `receipt_number` (sequence do PostgreSQL), marca a fatura `paid` com `paid_at`, e cria o lançamento de receita na categoria "Mensalidades" com status `paid`, vinculado ao pagamento.
- [ ] Dois pagamentos simultâneos da mesma fatura resultam em apenas um pagamento.
- [ ] Após a baixa: toast de sucesso com botão "Imprimir recibo".
- [ ] Disponível a partir da consulta rápida, da ficha e da tela de mensalidades.
- [ ] Exige permissão `billing.pay`.

**Expected Result:** A mensalidade fica paga, com recibo numerado e receita lançada no financeiro.

---

### US-9.2: Ter o cartão reativado ao pagar
**As a** Financeiro
**I want to** que o cartão suspenso seja reativado automaticamente quando o pagamento regulariza o titular
**So that** o cliente volte a ser atendido sem passo manual

**Acceptance Criteria:**
- [ ] Após a baixa, se o cartão está `suspended` e **não resta** fatura `overdue` com atraso ≥ `suspend_after_days`, o cartão volta a `active` com log "Reativação por pagamento".
- [ ] Se ainda restar fatura com atraso ≥ limite, o cartão continua suspenso.
- [ ] O modal de pagamento avisa antes da confirmação quando a baixa vai reativar o cartão.
- [ ] Cartão `cancelled` nunca é reativado por pagamento.

**Expected Result:** Titulares regularizados têm o cartão reativado no ato do pagamento.

---

### US-9.3: Emitir o recibo em PDF
**As a** Financeiro ou Admin
**I want to** imprimir o recibo de um pagamento
**So that** o cliente tenha o comprovante

**Acceptance Criteria:**
- [ ] `GET /recibos/{payment}` retorna PDF gerado sob demanda (não armazenado), nomeado `recibo-{receipt_number}.pdf`.
- [ ] O recibo contém dados da clínica (configurações), titular, cartão, referência, valor pago, forma e data.
- [ ] Exige permissão `billing.view`.

**Expected Result:** Qualquer pagamento pode ter seu recibo impresso a qualquer momento.

---

### US-9.4: Estornar um pagamento
**As a** Admin
**I want to** estornar um pagamento registrado por engano
**So that** a fatura e o caixa voltem ao estado correto

**Acceptance Criteria:**
- [ ] Ação disponível apenas com `billing.refund` (somente Admin).
- [ ] A fatura volta a `open` (se `due_date >= hoje`) ou `overdue` (se vencida) e `paid_at` é limpo.
- [ ] O lançamento de receita vinculado vai para `cancelled`.
- [ ] Nenhum registro é apagado; o estorno fica no activity log.

**Expected Result:** O pagamento é desfeito sem perda de histórico.

---

## 10. Financeiro

### US-10.1: Registrar lançamentos manuais
**As a** Financeiro ou Admin
**I want to** registrar receitas e despesas avulsas
**So that** todo o movimento da clínica esteja no sistema

**Acceptance Criteria:**
- [ ] Modal com tipo (receita/despesa), categoria (do mesmo tipo, ativa), descrição, valor, vencimento, fornecedor/pagador e observação; pode ser criado já baixado (com `paid_at` e forma de pagamento).
- [ ] Lista com filtros por tipo, categoria, status e período.
- [ ] Lançamentos automáticos de mensalidade exibem cadeado e **não podem ser editados** nem excluídos (só via estorno do pagamento).
- [ ] Lançamentos manuais usam soft delete; nunca são excluídos fisicamente.
- [ ] Exige `finance.view` para ver e `finance.manage` para criar/editar.

**Expected Result:** Receitas e despesas avulsas ficam registradas ao lado das receitas automáticas.

---

### US-10.2: Acompanhar as contas a pagar
**As a** Financeiro ou Admin
**I want to** ver as despesas pendentes agrupadas por vencimento
**So that** eu não perca prazos

**Acceptance Criteria:**
- [ ] Lista despesas `pending` agrupadas em vencidas, vencendo em 7 dias e futuras.
- [ ] Ação de baixa em cada conta.

**Expected Result:** As despesas pendentes são priorizadas pelo vencimento.

---

### US-10.3: Acompanhar as contas a receber
**As a** Financeiro ou Admin
**I want to** ver tudo o que a clínica tem a receber
**So that** eu projete as entradas

**Acceptance Criteria:**
- [ ] Lista receitas manuais `pending` + mensalidades `open`/`overdue`.
- [ ] Mensalidades aparecem somente leitura, com link para o faturamento.

**Expected Result:** Receitas manuais e mensalidades pendentes aparecem em uma única visão.

---

### US-10.4: Dar baixa em contas
**As a** Financeiro ou Admin
**I want to** registrar o pagamento ou recebimento de um lançamento pendente
**So that** ele entre no fluxo de caixa

**Acceptance Criteria:**
- [ ] Informa `paid_at` e forma de pagamento; status vai para `paid`.
- [ ] Lançamento já pago ou cancelado não pode receber nova baixa.
- [ ] Exige `finance.manage`.

**Expected Result:** O lançamento passa a contar no regime de caixa pela data efetiva.

---

### US-10.5: Gerenciar categorias financeiras
**As a** Financeiro ou Admin
**I want to** criar, editar e desativar categorias de receita e despesa
**So that** os lançamentos fiquem bem classificados

**Acceptance Criteria:**
- [ ] CRUD com nome, tipo e ativo/inativo.
- [ ] Categorias de sistema (ex.: "Mensalidades") não podem ser excluídas nem ter o tipo alterado.
- [ ] Categoria inativa não aparece para novos lançamentos.

**Expected Result:** As categorias são mantidas sem quebrar as de sistema.

---

### US-10.6: Acompanhar o fluxo de caixa
**As a** Financeiro ou Admin
**I want to** ver entradas, saídas e saldos por dia ou por mês
**So that** eu saiba a saúde financeira da clínica

**Acceptance Criteria:**
- [ ] Regime de caixa baseado em `paid_at`; lançamentos `cancelled` são ignorados.
- [ ] Saldo inicial = receitas pagas − despesas pagas antes do início do período.
- [ ] Visão **diária** (dias do mês selecionado) e **mensal** (meses do ano selecionado) com entradas, saídas, saldo do período e **saldo acumulado**, mais gráfico de barras.
- [ ] Linha opcional de **previsto** com base no `due_date` dos itens pendentes.

**Expected Result:** O fluxo de caixa mostra saldos corretos e acumulados no período escolhido.

---

## 11. Painel e relatórios

### US-11.1: Acompanhar os indicadores no painel
**As a** Financeiro ou Admin
**I want to** ver os principais indicadores do programa em um painel
**So that** eu acompanhe a operação de relance

**Acceptance Criteria:**
- [ ] Cards: cartões ativos, cartões em estoque (alerta abaixo de `min_card_stock`), vidas atendidas, receita do mês, despesa do mês, valor em aberto, valor em atraso e índice de inadimplência, calculados conforme as definições do projeto.
- [ ] Gráfico receita × despesa dos últimos 12 meses.
- [ ] Lista dos últimos pagamentos e dos 5 maiores atrasos (com link para a ficha).
- [ ] Exige permissão `dashboard.view`.

**Expected Result:** O painel resume cartões, receita, despesa e inadimplência com números corretos.

---

### US-11.2: Gerar relatórios em PDF e Excel
**As a** Financeiro ou Admin
**I want to** gerar relatórios por período em PDF ou Excel
**So that** eu analise e compartilhe os dados

**Acceptance Criteria:**
- [ ] Relatórios: clientes e dependentes (por plano/status); mensalidades por período e status; inadimplência; pagamentos recebidos por forma; lançamentos por categoria; fluxo de caixa.
- [ ] Cada relatório gera PDF e Excel a partir da mesma consulta.
- [ ] Exports grandes podem rodar em fila e notificar quando prontos.
- [ ] Exige permissão `reports.view`.

**Expected Result:** Os seis relatórios ficam disponíveis em PDF e Excel para qualquer período.

---

## 12. Configurações, auditoria e LGPD

### US-12.1: Configurar a clínica
**As a** Admin
**I want to** definir os dados da clínica e os parâmetros do programa
**So that** recibos, cobrança e alertas sigam as regras da clínica

**Acceptance Criteria:**
- [ ] Campos: nome, CNPJ, endereço e telefone da clínica; `default_due_day` (1–28, padrão 10); `suspend_after_days` (padrão 30); `invoice_generation_day` (1–28); `min_card_stock`; `card_number_pattern` (regex opcional, validada como expressão regular).
- [ ] Valores salvos passam a valer imediatamente para as rotinas e telas.
- [ ] Exige permissão `settings.manage` (somente Admin).

**Expected Result:** Os parâmetros do programa são ajustáveis sem mudar código.

---

### US-12.2: Auditar alterações
**As a** Admin
**I want to** que as alterações nos dados principais sejam registradas
**So that** eu saiba quem alterou o quê e quando

**Acceptance Criteria:**
- [ ] Activity log nos models de clientes, dependentes, cartões, planos, mensalidades, pagamentos e lançamentos, registrando apenas campos alterados e o usuário.
- [ ] Rotinas agendadas registram a quantidade de registros afetados.
- [ ] Pagamentos e lançamentos nunca são excluídos fisicamente; cadastros usam soft delete.

**Expected Result:** Toda alteração relevante é rastreável até o autor.

---

### US-12.3: Proteger dados pessoais
**As a** Admin
**I want to** que dados pessoais sejam exibidos e trafegados com segurança
**So that** a clínica cumpra a LGPD

**Acceptance Criteria:**
- [ ] CPF mascarado (`***.456.789-**`) em listagens e para o Atendente; completo apenas na ficha.
- [ ] HTTPS obrigatório e cookie de sessão seguro (`SESSION_SECURE_COOKIE=true`) em produção.

**Expected Result:** Dados pessoais só aparecem completos para quem precisa deles.

---

### US-12.4: Exportar os dados de um titular
**As a** Admin ou Financeiro
**I want to** exportar todos os dados de um titular a partir da ficha
**So that** a clínica atenda pedidos de acesso previstos na LGPD

**Acceptance Criteria:**
- [ ] Botão na ficha gera um arquivo com dados cadastrais, dependentes, cartões, mensalidades e pagamentos do titular.
- [ ] Exige permissão `customers.view`.

**Expected Result:** Os dados de qualquer titular podem ser entregues mediante solicitação.

---

## Open Questions

- **Dados da clínica** para recibos e configuração inicial (US-9.3, US-12.1).

## Appendix: User Story Status

| ID | Story | Priority | Status |
|----|-------|----------|--------|
| US-1.1 | Entrar no sistema | High | Pending |
| US-1.2 | Ser direcionado à tela certa do meu perfil | High | Pending |
| US-1.3 | Ter acesso negado ao que não é do meu perfil | High | Pending |
| US-1.4 | Gerenciar usuários | High | Pending |
| US-2.1 | Cadastrar lote de cartões por faixa | High | Pending |
| US-2.2 | Cadastrar lote de cartões por lista ou CSV | High | Pending |
| US-2.3 | Acompanhar o estoque e os lotes | High | Pending |
| US-2.4 | Inutilizar cartão danificado no estoque | High | Pending |
| US-3.1 | Gerenciar planos | High | Pending |
| US-4.1 | Realizar uma nova venda | High | Pending |
| US-4.2 | Validar o cartão em tempo real durante a venda | High | Pending |
| US-4.3 | Incluir dependentes já na venda | High | Pending |
| US-4.4 | Gerar a primeira mensalidade na adesão | High | Pending |
| US-4.5 | Listar e filtrar clientes | High | Pending |
| US-4.6 | Editar o cadastro de um cliente | High | Pending |
| US-4.7 | Consultar a ficha do cliente | High | Pending |
| US-4.8 | Ver o histórico do cliente | High | Pending |
| US-4.9 | Registrar observações sobre o cliente | High | Pending |
| US-5.1 | Encontrar um cliente rapidamente | High | Pending |
| US-5.2 | Encontrar o cliente por um cartão antigo | High | Pending |
| US-5.3 | Ver resultado restrito como Atendente | High | Pending |
| US-6.1 | Emitir segunda via do cartão | High | Pending |
| US-6.2 | Suspender e reativar o cartão manualmente | High | Pending |
| US-6.3 | Cancelar o cartão | High | Pending |
| US-6.4 | Reingressar um titular cancelado | High | Pending |
| US-7.1 | Gerenciar dependentes após a venda | High | Pending |
| US-7.2 | Trocar o plano do titular | High | Pending |
| US-8.1 | Gerar as mensalidades do mês automaticamente | High | Pending |
| US-8.2 | Marcar mensalidades vencidas como em atraso | High | Pending |
| US-8.3 | Suspender automaticamente cartões de inadimplentes | High | Pending |
| US-8.4 | Acompanhar as mensalidades | High | Pending |
| US-8.5 | Acompanhar os inadimplentes | High | Pending |
| US-9.1 | Registrar o pagamento de uma mensalidade | High | Pending |
| US-9.2 | Ter o cartão reativado ao pagar | High | Pending |
| US-9.3 | Emitir o recibo em PDF | High | Pending |
| US-9.4 | Estornar um pagamento | High | Pending |
| US-12.1 | Configurar a clínica | High | Pending |
| US-10.1 | Registrar lançamentos manuais | Medium | Pending |
| US-10.2 | Acompanhar as contas a pagar | Medium | Pending |
| US-10.3 | Acompanhar as contas a receber | Medium | Pending |
| US-10.4 | Dar baixa em contas | Medium | Pending |
| US-10.5 | Gerenciar categorias financeiras | Medium | Pending |
| US-10.6 | Acompanhar o fluxo de caixa | Medium | Pending |
| US-11.1 | Acompanhar os indicadores no painel | Medium | Pending |
| US-11.2 | Gerar relatórios em PDF e Excel | Medium | Pending |
| US-12.2 | Auditar alterações | Medium | Pending |
| US-12.3 | Proteger dados pessoais | Medium | Pending |
| US-12.4 | Exportar os dados de um titular | Low | Pending |
