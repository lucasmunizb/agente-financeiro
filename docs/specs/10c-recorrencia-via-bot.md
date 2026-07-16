# Spec 10c — Recorrência via bot/chat (extração pela IA)

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra inviolável 2), **backend antes do
> frontend** (regra 3). Em qualquer dúvida de regra, o **escopo final** e os
> `docs/` de referência **prevalecem** sobre suposições — não invente regra financeira.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Pós-MVP · recorrência (fecha a lacuna: recorrência só existia pela web) |
| **Status** | ✅ Concluído (backend + frontend) |
| **Depende de** | [[spec-04-ia-interpretacao]] (ExtratorDeGasto, slot-filling) · [[spec-04b-confirmacao-gasto-bot]] (fila `telegram_pending_confirmations`) · [[spec-10-recorrencia-mensal]] (domínio `Recorrencia`) |
| **Habilita** | Frontend: mensagem de confirmação de recorrência no bot/chat (etapa separada) |
| **Fonte de verdade** | [`docs/02-governanca-ia.md`](../02-governanca-ia.md) §3.1 (papéis da IA) · [`docs/03-regras-financeiras.md`](../03-regras-financeiras.md) §4.6 · [[spec-10-recorrencia-mensal]] §4 |
| **Regras críticas** | 2 (TDD) · 3 (frontend separado) · 4 (IA nunca calcula) · 5 (centavos/fuso SP) · 7 (confirmar antes de gravar) |

---

## 1. Objetivo

Permitir cadastrar uma **recorrência mensal** conversando com o bot ("pago o inglês da
Carol todo dia 10, 520 reais no pix"), com a mesma barreira de confirmação da web: nada é
gravado sem o "sim" (regra 7).

## 2. Motivação (incidente de produção — 2026-07-16)

Mensagem real: *"registra uma recorrencia no pix para pagar todo dia 10 520 reias ingles
carol categoria estudos"*. O bot respondeu **"Para registrar, me diga também a data e a
forma de pagamento"** — pedindo dois campos que a mensagem **continha**.

Causa raiz apurada:

1. **Recorrência não existia no pipeline de IA.** O schema do `ExtratorDeGasto` tinha 7
   campos e nenhum de recorrência; `Intencao` só tinha REGISTRAR/CONSULTAR. "todo dia 10"
   não tinha para onde ir e caiu no campo `data`, que `NormalizadorDeGastoExtraido::
   resolverData()` não parseia (não é uma data) → esclarecimento `data`.
2. **`forma_pagamento` sem normalização determinística.** A mensagem de erro tinha
   exatamente 2 campos na ordem do `NormalizadorDeGastoExtraido` (valor → data →
   forma_pagamento), logo a **barreira 1** (`GastoParcial::faltantes()`, que **não** checa
   `data`) passou — ou seja, a IA **extraiu** a forma. Quem rejeitou foi
   `PaymentMethod::idFor()`, que faz `where('tipo', $tipo)` **exato**: qualquer coisa fora
   de `pix` literal (`"PIX"`, `"pix recorrente"`) devolve null.

> **Por que os testes não pegaram:** toda a cobertura de extração usa `Ai::fakeAgent`, e o
> fake devolve o payload que **nós** escrevemos — sempre `"pix"` certinho. O defeito estava
> na saída do **modelo real**. Fica registrado como dívida: um *golden set* contra o
> provedor real (fora do gate do CI) é o único teste que pegaria essa classe de regressão.

## 3. Cenários de aceite (Given-When-Then)

- **C1 (extrair recorrência) — Dado** "pago inglês da Carol todo dia 10, 520 reais no pix",
  **Quando** o bot processa, **Então** a extração traz `recorrencia_dia = "10"` (texto cru)
  e **não** pede `data`.
- **C2 (data não é exigida em recorrência) — Dado** `recorrencia_dia` preenchido e `data`
  **ausente**, **Quando** normaliza, **Então** **não** há esclarecimento `data` (recorrência
  não tem data de compra; tem dia-do-mês).
- **C3 (data ignorada, não silenciada) — Dado** `recorrencia_dia = "10"` e `data = "todo dia
  10"` (o modelo repetiu o texto nos dois campos), **Quando** normaliza, **Então** a `data`
  é **descartada** sem esclarecimento — o `dia` é a verdade da recorrência.
- **C4 (dia inválido) — Dado** `recorrencia_dia = "35"` (ou "todo dia zero", ou texto sem
  dígito), **Quando** normaliza, **Então** esclarecimento `recorrencia_dia` — **nunca** chuta
  (§3.4 da spec 04).
- **C5 (clamp é do domínio, não da IA) — Dado** `recorrencia_dia = "31"`, **Quando** o
  usuário confirma, **Então** `RegistrarRecorrencia` grava `dia = 31` e o clamp ao fim do mês
  é do `OcorrenciaMensal` (regra 4: a IA não calcula data).
- **C6 (crédito recusado) — Dado** `recorrencia_dia = "10"` e `forma_pagamento = "credito"`,
  **Quando** normaliza, **Então** esclarecimento `forma_pagamento` (recorrência é **só fora
  de cartão** — crédito usa parcelas, C3 da spec 10). Nunca chega a `RegistrarRecorrencia`
  para estourar `InvalidArgumentException` na cara do usuário.
- **C7 (parcelas + recorrência é contradição) — Dado** `recorrencia_dia` **e** `parcelas ≥ 2`,
  **Então** esclarecimento `recorrencia_dia` (ou é parcelado, ou é recorrente — não os dois).
- **C8 (forma normalizada) — Dado** que a IA devolve `"PIX"`, `" Pix "` ou `"Pix"`,
  **Quando** normaliza, **Então** casa `pix` (normalização determinística: caixa/acento/
  espaço, via `Normalizador::texto`). **Fecha a causa raiz 2 do incidente.**
- **C9 (forma fora do conjunto) — Dado** `"pix recorrente"` (fora de `PaymentMethod::TIPOS`),
  **Então** esclarecimento `forma_pagamento` — pergunta, não chuta.
- **C10 (confirmar grava recorrência) — Dado** a prévia de recorrência pendente, **Quando** o
  usuário diz "sim", **Então** nasce um `Recurrence` `ativo` via `RegistrarRecorrencia` e
  **nenhum** `Transaction` é criado (o lançamento nasce depois, no materializador — spec 10).
- **C11 (não descarta) — Dado** a prévia de recorrência pendente, **Quando** o usuário diz
  "não", **Então** nada é gravado e o pendente é descartado.
- **C12 (slot-filling multi-turno) — Dado** "pago inglês da Carol todo dia 10" (sem valor e
  sem forma), **Quando** o usuário responde "520 no pix" no turno seguinte, **Então** o
  `recorrencia_dia` do 1º turno é **preservado** pelo `mesclar()` e a prévia fecha.
- **C13 (gasto avulso intacto) — Dado** `recorrencia_dia = null`, **Então** o fluxo é
  **idêntico** ao de hoje (`DadosGastoManual`, `data` ausente → hoje). Regressão zero.
- **C14 (escopo) — Dado** dois usuários, **Então** a recorrência nasce estritamente sob o
  `user_id` do autor da mensagem.

## 4. Barreiras e invariantes

- **Regra 4 — a IA nunca calcula:** `recorrencia_dia` sai da IA como **texto cru** ("10"),
  como já acontece com `valor` e `data`. Quem converte para `int`, valida 1..31 e resolve
  `proxima_em` (com clamp) é o domínio determinístico (`OcorrenciaMensal`).
- **Regra 7 — confirmar antes de gravar:** a recorrência vira **prévia** + pendente com TTL;
  só o "sim" chama `RegistrarRecorrencia`.
- **Regra 5:** valor em centavos via `Money::fromHuman`; `dia`/datas no fuso SP; "agora"
  sempre injetado.
- **Recorrência é só fora de cartão** (herdado da spec 10 §2): crédito → esclarecimento, e a
  validação do `RegistrarRecorrencia` permanece como rede de segurança do domínio.
- **Campo que não resolve vira PERGUNTA, nunca chute** (spec 04 §3.4).
- **Escopo estrito por `user_id`.**

## 5. Modelo de dados

**Nenhuma tabela nova.** Reusa `recurrences` (spec 10) e a fila
`telegram_pending_confirmations` (spec 04b), cujo `payload` (jsonb) passa a serializar
**ou** um gasto **ou** uma recorrência — discriminado por uma chave `tipo` **dentro do
payload** (`gasto` | `recorrencia`), sem migration.

> A coluna `tipo` da tabela continua sendo o discriminador **da fila**
> (`confirmacao` | `esclarecimento`) — não confundir com o `tipo` **do payload**.

## 6. Contratos do domínio

- `App\Domain\IA\GastoParcial` — **+`?string $recorrenciaDiaTexto`** (mescla igual aos
  demais: não-nulo do turno novo vence). `faltantes()` inalterado.
- `App\Domain\IA\GastoExtraido` — **+`?string $recorrenciaDiaTexto`**.
- `App\Ai\Agents\ExtratorDeGasto::schema()` — **+`recorrencia_dia`** (string, nullable) e
  `instructions()` ganha a regra: dia-do-mês só quando o usuário disser que **repete**;
  copiar o número, nunca calcular.
- `App\Domain\IA\ResultadoDaNormalizacao` — passa a carregar **`?DadosGastoManual $dados`
  XOR `?DadosRecorrencia $recorrencia`** + `esclarecimentos`.
- `App\Domain\IA\ConfirmacaoDeGasto` — **+`?DadosRecorrencia $recorrencia`**; `confirmavel()`
  passa a valer para prévia de gasto **ou** de recorrência.
- `App\Domain\IA\NormalizadorDeGastoExtraido` — bifurca por `recorrenciaDiaTexto`;
  **+`resolverDia()`** (texto cru → int 1..31 ou null) e **+ normalização determinística da
  forma de pagamento** antes do `PaymentMethod::idFor()` (C8).
- `App\Models\PaymentMethod::idFor()` — passa a casar pelo tipo **normalizado**
  (`Normalizador::texto`), não pelo literal cru.
- `App\Domain\Telegram\Confirmacao\ConfirmacoesPendentes` — serializa/desserializa os dois
  tipos de payload.
- `App\Domain\Telegram\Confirmacao\ConfirmarGastoPendente` — no "sim", despacha para
  `RegistrarGastoManual` **ou** `RegistrarRecorrencia`.
- `App\Domain\Telegram\Resposta\ResultadoDaInteracao` — **+`recorrenciaGravada(Recurrence)`**.

## 7. Plano de testes (test-first — devem falhar primeiro)

1. **Unitários do domínio**
   - `tests/Unit/IA/GastoParcialTest.php` — `recorrenciaDiaTexto` mescla e é preservado entre
     turnos (C12); `paraExtraido()` propaga.
   - `tests/Unit/AI/ExtratorDeGastoTest.php` — `recorrencia_dia` declarado no schema, string
     nullable, compatível com o strict da Groq; `instructions()` cita a regra do dia.
   - `tests/Unit/Shared/PaymentMethodNormalizacaoTest.php` — `"PIX"`/`" Pix "` → `pix` (C8).
2. **Contrato/integração** (fakes da Laravel AI SDK)
   - `tests/Feature/AI/NormalizadorDeGastoExtraidoTest.php` — C2, C3, C4, C6, C7, C8, C9, C13.
   - `tests/Feature/AI/PrepararConfirmacaoDeGastoTest.php` — prévia de recorrência (C5).
   - `tests/Feature/IA/RecorrenciaViaBotTest.php` (novo) — ponta a ponta via
     `ProcessarInteracao` com `Ai::fakeAgent`: C1, C10, C11, C12, C14. **Inclui a mensagem
     literal do incidente como caso de teste.**

> Cada item de backend só é "feito" com **testes verdes e cobertura**.

## 8. Backend agora · Frontend depois

| Backend (commit 1) | Frontend (commit 2 — separado, regra 3) |
|---|---|
| Schema do extrator, DTOs, normalização, bifurcação da confirmação, gravar no "sim" | Prévia da recorrência e texto de `RECORRENCIA_GRAVADA` no `RedatorDoChat` (bot **e** web, pois `RespostaTelegram` reusa o redator), rótulo pt-BR de `recorrencia_dia`, VO `PreviaRecorrencia` + `RegistrarRecorrencia::preview()` |

**Texto entregue** (verificado executando o redator, não só por asserção):

```
Confirme a recorrência:
ingles carol — R$ 520,00, todo dia 10, no pix.
Categoria: Estudos.
Todo mês eu te lembro para confirmar o pagamento.
Responda "sim" para gravar ou "não" para cancelar.
```
```
Pronto, criei a recorrência: ingles carol — R$ 520,00, todo dia 10.
Te lembro no dia para confirmar o pagamento.
```

> A redação evita "registrei" de propósito: o "sim" cria o **molde**, nenhum lançamento nasce
> ali — ele vem do materializador, no dia (spec 10). Dizer "registrei" faria o usuário
> acreditar que o gasto do mês já está lançado.

## 9. Definition of Done

- [x] Cenários de §3 cobertos por testes que falhavam antes e agora passam.
- [x] Barreiras de §4 garantidas (teste para cada uma).
- [x] **C13 verde**: nenhuma regressão no fluxo de gasto avulso (suíte inteira: 1109 passed).
- [x] Sem segredo/dado sensível persistido ou commitado.
- [ ] Commit local atômico, em português, separando backend de frontend.
- [x] §10 preenchida com os artefatos reais.

## 10. Estado atual / artefatos

- **Status:** ✅ Backend + frontend concluídos (2026-07-16). Suíte: **1114 passed**, sem migration.
- **Entregue:**
  - `app/Ai/Agents/ExtratorDeGasto.php` — campo `recorrencia_dia` no schema + regra nas
    instruções (distingue "todo dia 10" de "paguei dia 10").
  - `app/Domain/IA/GastoParcial.php` · `GastoExtraido.php` — slot `recorrenciaDiaTexto`
    (mescla no multi-turno; não obrigatório).
  - `app/Domain/IA/NormalizadorDeGastoExtraido.php` — bifurcação `normalizarRecorrencia()`
    / `normalizarGasto()`, `resolverDia()`, forma comparada já normalizada.
  - `app/Domain/IA/ResultadoDaNormalizacao.php` · `ConfirmacaoDeGasto.php`
    (`ehRecorrencia()`) · `PrepararConfirmacaoDeGasto.php`.
  - `app/Models/PaymentMethod.php` — `idFor()` normaliza antes do lookup e confronta com
    `TIPOS` (**fecha a causa raiz 2**; conserta bot, chat e importação de uma vez).
  - `app/Domain/IA/Esclarecimento/EsclarecimentosPendentes.php` — serializa o dia (sem ele
    o slot-filling perdia o "todo dia 10" no 2º turno — pego pelo C12).
  - `app/Domain/Telegram/Confirmacao/ConfirmacoesPendentes.php` — payload discriminado por
    `molde` (`gasto`|`recorrencia`), retrocompatível (ausente ⇒ gasto).
  - `app/Domain/Telegram/Confirmacao/ConfirmarGastoPendente.php` — "sim" despacha para
    `RegistrarGastoManual` ou `RegistrarRecorrencia`.
  - `app/Domain/Telegram/Resposta/{ResultadoDaInteracao,TipoDeInteracao}.php` —
    `RECORRENCIA_GRAVADA` · `app/Domain/Interacao/ProcessarInteracao.php`.
  - **Frontend (commit separado):** `app/Domain/Recorrencia/PreviaRecorrencia.php` (novo) ·
    `RegistrarRecorrencia::preview()` (resolve forma e categoria em texto no domínio, para a
    redação não consultar o banco) · `ConfirmacaoDeGasto` ganha o par
    `previaRecorrencia`/`recorrencia` · `RedatorDoChat` (prévia, `RECORRENCIA_GRAVADA`,
    rótulo `recorrencia_dia`). `RespostaTelegram` **não mudou**: reusa o `RedatorDoChat`.
  - Testes: `tests/Feature/IA/RecorrenciaViaBotTest.php` (novo, com a mensagem literal do
    incidente) · `tests/Feature/AI/NormalizadorDeGastoExtraidoTest.php` ·
    `tests/Unit/IA/GastoParcialTest.php` · `tests/Unit/AI/ExtratorDeGastoTest.php` ·
    `tests/Unit/Chat/RedatorDoChatTest.php`.
- **Adiado para:** *golden set* contra provedor real (dívida registrada em §2) — é o único
  teste que pegaria a classe de bug deste incidente.
- **Decisões de regra tomadas:** recorrência entra como **campo do `ExtratorDeGasto`** (1
  chamada de IA, reusa o slot-filling) em vez de intenção/agent próprio — decisão do usuário
  em 2026-07-16, motivada por custo de token e por evitar que o classificador confunda
  "paguei o inglês dia 10" (avulso) com "pago o inglês todo dia 10" (recorrente).
