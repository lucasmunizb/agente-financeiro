---
name: laravel-backend
description: Use sempre que for escrever, revisar ou planejar código de backend deste projeto (Laravel 12 / PHP 8.3 / PostgreSQL 16) — domínio financeiro, motor de cálculo, parcelas, vencimentos, disponível do mês, duplicidade, migrations, models Eloquent, Form Requests, services, jobs/filas, ou integração de IA via Laravel AI SDK. Dispare também quando o usuário mencionar "motor financeiro", "centavos", "parcela", "vencimento", "disponível do mês", "TDD", "teste primeiro", "migration" ou "domínio" — mesmo sem pedir explicitamente. NÃO use para construir mensagens do bot ou telas web (isso é frontend, etapa separada).
---

# laravel-backend — backend deste projeto

Convenções e padrões de backend **para ESTE projeto** (não um guia genérico de Laravel).
A fonte de verdade do escopo está em [`/docs`](../../../docs/) e em `CLAUDE.md`.

## Stack
- **Laravel 12 + PHP 8.3 + PostgreSQL 16.**
- Fila no driver **`database`** (MVP); worker dedicado (`queue:work` + `schedule:work`).
- Testes com **Pest/PHPUnit**. Tudo roda **em contêiner** (`make test`, `make artisan …`).

## Organização por domínio (estilo DDD)
- A **regra de negócio vive no domínio/serviços**, não nos controllers/handlers.
- **Canais (web/Telegram) são bordas finas**: validam, traduzem e delegam ao domínio.
- O **motor financeiro é o núcleo testável** — determinístico, 100% coberto por testes.
- Sugestão de layout: `app/Domain/<Contexto>/…` (Services, ValueObjects, DTOs) com Eloquent
  em `app/Models`. Mantenha o cálculo financeiro fora dos models "anêmicos".

## Test-first / TDD (obrigatório)
Para cada feature: **teste que falha → implementar → refatorar**. Nunca implemente antes do
teste. **Nunca** construa frontend (mensagem do bot/telas) junto da feature de backend —
é etapa separada e posterior. Metas de cobertura altas no domínio financeiro (é o núcleo
de risco). Para IA, use os **fakes da Laravel AI SDK** para testes determinísticos.

Anuncie antes de codar: (a) testes que vai escrever, (b) o que é backend agora, (c) o que
fica para o frontend depois.

## Domínio financeiro (regras determinísticas)

> Detalhes completos em [`docs/03-regras-financeiras.md`](../../../docs/03-regras-financeiras.md).

- **Dinheiro em BIGINT centavos.** Nunca float. Formatação pt-BR **só na borda** (ex.: um
  `Money` value object ou helper `formatBRL(int $cents)`). Migrations usam
  `$table->bigInteger('valor_total_cents')`.
- **Fuso `America/Sao_Paulo`** para datas relativas (hoje, ontem=-1, amanhã=+1, "mês que
  vem"=dia 05 do próximo mês) e vencimentos.
- **Parcelas:** na 1/N, gerar todas as N futuras. Ao importar parcela ≥2/N, registrar todas
  se não existirem. **Parcela atual é sempre calculada na exibição, nunca fixada.**
- **Vencimentos:** compra em cartão respeita SEMPRE o vencimento do cartão; fora de cartão
  (PIX/débito/dinheiro) vence na data da parcela/compra.
- **Disponível do mês** (fórmula oficial) = Receitas recebidas no mês − cartões com
  vencimento no mês − gastos fora de cartão (PIX/débito). Reseta mensal; reserva não entra;
  transferências não contam; cartão não fechado entra a partir do fechamento.
- **Status de pagamento** = tabela de referência (`status_pagamento`) com FK: `aberto`,
  `pago`, `pago_parcial`, `vencido`, `cancelado`, `estornado`, `pendente_revisao`,
  `agendado`.
- **Duplicidade:** valor + descrição + data + quantidade de parcelas (NUNCA pela parcela
  atual).

### Exemplo de teste (Pest) — normalização monetária
```php
it('normaliza "35 conto" para 3500 centavos', function () {
    expect(Money::fromHuman('35 conto')->cents())->toBe(3500);
});

it('formata centavos em pt-BR só na borda', function () {
    expect(Money::fromCents(123456)->formatBRL())->toBe('R$ 1.234,56');
});
```

### Exemplo de teste (Pest) — disponível do mês
```php
it('calcula o disponível do mês pela fórmula oficial', function () {
    // receitas 5000,00 ; cartão vencendo no mês 1200,00 ; pix do mês 300,00
    $disponivel = app(DisponivelDoMes::class)->para($user, '2026-06');
    expect($disponivel->cents())->toBe(350000); // 5000 - 1200 - 300 = 3500,00
});
```

### Exemplo de teste (Pest) — geração de parcelas
```php
it('gera N parcelas futuras a partir da 1/N', function () {
    $tx = ParcelarCompra::handle(user: $user, totalCents: 120000, parcelas: 3, /* … */);
    expect($tx->installments)->toHaveCount(3);
    // vencimentos +1 mês a cada parcela; valor por parcela determinístico
});
```

## Persistência
- **Migrations versionadas e idempotentes**; rode via `make migrate`.
- Eloquent para acesso; cálculo financeiro **fora** do model.
- **Soft delete + auditoria** (`audit_log`) em tabelas sensíveis (LGPD — exclusão lógica).
- **Isolamento por `user_id`** em todo registro (preparado para multiusuário). Toda query
  do domínio e toda Tool de IA filtra por usuário autenticado.

## Validação e contratos
- **Form Requests / validação na borda** (web e handler do Telegram).
- Respostas de API consistentes (mesmo envelope de sucesso/erro).
- **Confirmação antes de persistir** em todo registro/edição (MVP).

## IA no backend (Laravel AI SDK)
- Integração **somente** via `laravel/ai`: Agents (`make:agent`), Tools (`make:tool`),
  `HasStructuredOutput`, `RemembersConversations`, failover (array de provedores / enum
  `Lab`), `queue()`. Sem cliente HTTP próprio.
- **Guard determinístico é camada nossa:** as Tools financeiras devolvem valores **já
  calculados pelo domínio**; antes de enviar a resposta da IA, valide que todo número/data
  do texto existe no payload calculado (divergência → bloqueia/regenera).
- Detalhes em [`docs/02-governanca-ia.md`](../../../docs/02-governanca-ia.md). Skill
  dedicada `governanca-ia` virá depois.

## Regras invioláveis (relembrando)
- **Test-first**, **frontend separado**, **IA nunca calcula dinheiro**, **centavos
  inteiros**, **confirmação antes de persistir**.
- **Tudo via contêiner** — `artisan`/`composer`/testes por `docker compose exec` (Makefile),
  nunca no host.
- **NUNCA `git push`** — commits locais apenas.
