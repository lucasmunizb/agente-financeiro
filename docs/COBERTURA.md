# Cobertura de testes — evidência

Esta página existe para que os badges do [`README`](../README.md) **não sejam decoração**.
Todo número publicado lá tem aqui o output real que o produziu, e um comando que o reproduz.

| Fato | Valor |
|---|---|
| Testes | **1321 passando** (3617 asserções) |
| Arquivos de teste | 167 |
| Cobertura de linhas | **96,7 %** |
| Duração da suíte | 51,78 s |
| Ferramenta | Pest 3 / PHPUnit 11 · driver de cobertura **PCOV** |
| Medido em | **2026-08-07**, commit [`24de4b8`](https://github.com/lucasmunizb/agente-financeiro/commit/24de4b8) |

---

## Print do output

![Output do Pest com cobertura](assets/pest-coverage.svg)

> O print é um recorte fiel do output: o topo da tabela de cobertura, os últimos testes
> executados e o rodapé com o total. A tabela completa tem 258 arquivos — o run inteiro está
> reproduzível com um comando (abaixo).

---

## Como reproduzir

Pré-requisito único no host: Docker + `make` (regra inviolável 9 — nada roda no host).

```bash
make up          # sobe app + worker + postgres
make coverage    # roda a suíte inteira com PCOV e imprime a tabela de cobertura
```

O alvo é uma casca fina sobre o contêiner:

```makefile
coverage: db-test
	$(EXEC_TEST) php -d pcov.enabled=1 -d memory_limit=1G vendor/bin/pest --coverage
```

Para só a suíte, sem instrumentação de cobertura (bem mais rápido):

```bash
make test        # docker compose exec app php artisan test
```

---

## O que a cobertura cobre — e o que ela não prova

Cobertura é **linha executada**, não comportamento correto. O que sustenta a qualidade aqui é a
disciplina test-first: o teste que falha vem antes do código da feature
(ver [`10-estrategia-testes.md`](10-estrategia-testes.md)).

Onde os 3,3 % descobertos se concentram:

- Alguns *accessors* e *scopes* pouco usados em models (`PendingConfirmation`, `Recurrence`,
  `RecurrenceOccurrence`, `TelegramPendingConfirmation`).
- Ramos de bootstrap em `AppServiceProvider` que só executam sob configuração de produção.
- Caminhos de erro de infraestrutura externa (falha de provedor de IA já em cooldown).

A camada de IA é testada **offline e determinística** com os *fakes* da Laravel AI SDK
(`Ai::fakeAgent`, `assertAgentWasPrompted`) — nenhum teste chama provedor real.

---

## Estado no CI

O gate de teste do [`deploy.yml`](../.github/workflows/deploy.yml) roda PHPStan + a suíte
completa: **se a suíte falhar, nada é publicado**.

O passo de **cobertura** roda no CI mas ainda é **informativo** (`continue-on-error`, sem
`--min`). Está registrado como pendência aberta no README, seção *"O que ainda não está
pronto"* — quando o limiar for decidido, basta remover o `continue-on-error` e acrescentar
`--min=<N>` ao `pest`.
