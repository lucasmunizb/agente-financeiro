# Spec 05 — Chat financeiro (tools + guard)

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra inviolável 2), **backend antes do
> frontend** (regra 3). Em qualquer dúvida de regra, o **escopo final** e os
> `docs/` de referência **prevalecem** sobre suposições — não invente regra financeira.
>
> Um spec é "vivo": ao concluir, marque o status, preencha **§10 Estado atual** com os
> artefatos reais (arquivos, comandos) e registre as decisões de regra que você tomou.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Bloco 5 · F7 |
| **Status** | ✅ Concluído (backend) |
| **Depende de** | [[spec-01-dominio-financeiro]] · [[spec-03-telegram]] · [[spec-04-ia-interpretacao]] |
| **Habilita** | [[spec-06-dashboard]] |
| **Fonte de verdade** | Seção 3 do escopo · [`docs/02-governanca-ia.md`](../02-governanca-ia.md) (§3.2 tools com escopo; barreiras 3, 4 e 5) |
| **Regras críticas** | 2 (TDD), 3 (frontend separado), 4 (IA nunca calcula), 5 (centavos/fuso SP), 8 (IA via SDK) |

---

## 1. Objetivo
Responder perguntas financeiras do usuário em linguagem natural usando **apenas números
calculados deterministicamente pelo motor financeiro**: a IA decide quais ferramentas de
consulta chamar e **redige** a resposta, mas **nunca calcula nem inventa** valores ou datas.

## 2. Escopo
- **Inclui (backend desta etapa):**
  - As **4 ferramentas de consulta** com escopo **estrito por `user_id`** (doc 02 §3.2):
    `consultar_gastos`, `consultar_disponivel_mes`, `consultar_proximas_contas`,
    `consultar_fatura_cartao` — cada uma chama a camada de consulta determinística e
    devolve ao modelo o **texto já calculado em pt-BR** + uma **fonte/trace**.
  - O **agente** `AssistenteDeConsulta` que expõe as 4 tools amarradas ao usuário e a um coletor.
  - O **coletor** do conjunto-verdade (payload + trace) de cada tool chamada.
  - O **guard pós-geração** (barreira 4) e o **orquestrador** `ResponderConsulta`:
    regenera em divergência (até 2 gerações) e cai em **fallback sem números** se esgotar.
  - A resposta carregando **fontes/trace** (barreira 5).
- **Não inclui (outro spec / frontend / pós-MVP):**
  - **Apresentação** das respostas no bot do Telegram / web (mensagens formatadas, telas):
    etapa separada e posterior (regra 3) — ver §8.
  - As camadas de consulta determinística em si (motor financeiro) já vêm de
    [[spec-01-dominio-financeiro]] / [[spec-02-cadastro-manual-receitas]]; aqui apenas
    são **acionadas** pelas tools.
  - Classificação de intenção / extração (Bloco 4, [[spec-04-ia-interpretacao]]).

## 3. Cenários de aceite (Given-When-Then)

- **C1 (caminho feliz) — Dado** um usuário com gastos no período **Quando** ele pergunta
  "quanto gastei?" **Então** o agente chama `consultar_gastos`, o coletor registra o
  payload calculado, a IA redige citando **só** valores do payload e o guard **aprova**.
- **C2 (escopo estrito) — Dado** dois usuários **Quando** a tool de um deles consulta
  **Então** ela só enxerga dados **do próprio `user_id`**; o modelo nunca passa
  identificador de usuário (a tool é construída **amarrada** ao usuário autenticado).
- **C3 (determinismo de "agora") — Dado** que o período/janela não foi informado **Quando**
  a tool roda **Então** "hoje"/"mês corrente" é resolvido **no servidor, fuso São Paulo**
  (`America/Sao_Paulo`) — a IA jamais resolve datas.
- **C4 (guard bloqueia + regenera) — Dado** que a IA citou um valor que **não** existe no
  payload **Quando** o guard valida **Então** **reprova**, descarta a resposta e
  **regenera** (nova geração com as tools de novo).
- **C5 (fallback) — Dado** que as 2 tentativas divergiram **Quando** esgotam **Então** o
  orquestrador devolve a **mensagem de fallback sem nenhum número/data** (nunca arrisca um
  valor alucinado — regra 4).
- **C6 (sem ferramenta / payload vazio) — Dado** uma pergunta não-financeira **Quando** a
  IA responde **sem citar números Então** o guard **aprova**; mas se ela inventa um número
  com payload vazio, o guard **bloqueia**.
- **C7 (multi-tool) — Dado** uma pergunta que aciona mais de uma tool **Quando** o coletor
  combina os payloads **Então** o guard valida contra o **conjunto-verdade combinado** de
  todas as tools chamadas.
- **C8 (fonte/trace — barreira 5) — Dado** uma resposta aprovada **Quando** ela é devolvida
  **Então** carrega as **fontes** (ferramenta, filtros, nº de registros) de cada tool usada.

## 4. Barreiras e invariantes
- **Regra 4 — a IA nunca calcula dinheiro.** As tools devolvem números **já calculados**
  pelo domínio; o **guard pós-geração** é a rede determinística que valida que **todo**
  valor monetário e data do texto redigido **existe no payload**. Divergência → bloqueia.
- **Barreira 3 — escopo por usuário, sem SQL livre, sem escrita.** Cada tool é amarrada ao
  `User` autenticado; o modelo só passa filtros, nunca `user_id`. Cartão/categoria não
  encontrados resolvem para consulta **vazia** (nunca vazam dados de terceiros).
- **Determinismo de "agora".** "Hoje"/"mês corrente" injetado no servidor (fuso SP); nunca
  lido pela IA. Mesma regra 5 dos blocos anteriores.
- **Centavos inteiros (regra 5).** Payload e comparações em **BIGINT centavos**; pt-BR só
  na borda (`paraPrompt()` / formatação). O guard parseia o texto pt-BR de volta a centavos.
- **Barreira 5 — fonte e explicação.** Toda resposta carrega o trace (período/filtros/nº de
  registros). Detalhes internos (query, payload, trace) **nunca** são revelados ao usuário.
- **IA via SDK (regra 8).** Agente + tools são da Laravel AI SDK; o guard é **camada nossa**
  por cima da SDK (a SDK não garante que um número veio do banco — doc 02 §3.7).
- **Sem dado sensível persistido (regra 6).** Tools são somente-leitura; nada do texto de
  consulta é gravado além dos metadados de uso em `ai_usage_log` (sem conteúdo).

## 5. Modelo de dados
**Nenhuma tabela criada nesta etapa.** As tools **leem** o schema já existente
(`installments`, `transactions`, `cards`, `categories`, `incomes`, `status_pagamento`).
Cada geração registra **uma linha** em `ai_usage_log` (append-only, só metadados — provider,
model, tokens, custo em centavos, latência, tipo `mensagem`, `user_id`); tabela criada no
Bloco 4.

## 6. Contratos do domínio
Assinaturas reais (PHP 8.3). A IA entra só para **decidir tools e redigir**; o cálculo e a
validação são determinísticos.

**Tools (Laravel AI SDK — `App\Ai\Tools\`)** — cada uma `implements Tool`, construída
`__construct(User $user, ?ColetorDeConsultas $coletor = null)`, com `handle(Request): Stringable|string`
(devolve `paraPrompt()` e registra `payload()` + `trace` no coletor) e `schema(JsonSchema)`:
- `ConsultarGastos` — schema `periodo? (YYYY-MM), categoria?, cartao?, status?`.
- `ConsultarDisponivelMes` — schema `mes? (YYYY-MM)`.
- `ConsultarProximasContas` — schema `janela? (int dias, padrão 30)`; "hoje" = `now(SP)`.
- `ConsultarFaturaCartao` — schema `cartao, competencia? (YYYY-MM)`.

**Agente (`App\Ai\Agents\AssistenteDeConsulta`)** —
`implements Agent, Conversational, HasTools`; `__construct(User $user, ColetorDeConsultas $coletor)`;
`instructions()` (curta, pt-BR, "nunca invente número" + defesa contra prompt injection);
`tools()` devolve as 4 tools amarradas ao usuário e ao coletor.

**Orquestração (`App\Domain\IA\Consulta\`)**
- `ResponderConsulta::responder(User $user, string $pergunta): RespostaDaConsulta` —
  loop até `MAX_TENTATIVAS = 2`: limpa o coletor, faz `prompt()`, registra uso, valida com o
  guard; aprovado → retorna; esgotou → `RespostaDaConsulta` com `FALLBACK` (sem números).
- `ColetorDeConsultas::registrar(PayloadDeResposta, TraceDaConsulta)`,
  `payloadCombinado(): PayloadDeResposta`, `fontes(): list<TraceDaConsulta>`, `limpar()`.
- `RespostaDaConsulta(string $texto, bool $aprovado, array $fontes, int $tentativas)` (VO).
- `TraceDaConsulta(string $ferramenta, array $filtros, int $registros)` + `resumo(): string`.

**Guard (`App\Domain\IA\Guard\`)**
- `GuardPosGeracao::validar(string $texto, PayloadDeResposta $payload): ResultadoDoGuard` —
  extrai por regex os valores monetários pt-BR e datas `dd/mm[/aaaa]` do texto e checa cada
  um contra o payload; contagens não-monetárias ("3 parcelas") são ignoradas de propósito.
- `PayloadDeResposta(array $valoresEmCentavos = [], array $datas = [])` com
  `static combinar(self ...$payloads)`, `permiteValor(int $cents): bool`,
  `permiteData(int $dia, int $mes, ?int $ano): bool`.
- `ResultadoDoGuard(bool $aprovado, array $valoresDivergentes, array $datasDivergentes)` (VO).

**Camadas de consulta determinística (`App\Domain\…`)** — `para(...)` por tool, escopo
estrito por `userId`, devolvendo um `Resultado…` com `payload(): PayloadDeResposta`,
`trace: TraceDaConsulta` e `paraPrompt(): string`:
`Gastos\ConsultarGastos`, `Disponivel\ConsultarDisponivelDoMes`,
`ProximasContas\ConsultarProximasContas`, `FaturaCartao\ConsultarFaturaCartao`.

## 7. Plano de testes (test-first — devem falhar primeiro)
1. **Unitários do domínio**
   - `GuardPosGeracao`: aprova texto sem número; aprova quando todo valor/data existe no
     payload (inclui milhar pt-BR e negativo); **bloqueia** valor/data divergente e o
     **reporta**; ignora contagens não-monetárias; valida data `dd/mm` sem ano.
   - `ColetorDeConsultas` / `PayloadDeResposta::combinar`: acumula payloads e fontes; combina
     o conjunto-verdade; `combinar()` sem argumentos = payload vazio; `limpar()` zera.
   - Camadas de consulta (`ConsultarGastos`/`Disponivel`/`ProximasContas`/`FaturaCartao`):
     totais corretos, escopo por usuário, "agora" injetado, status excluídos (§4.4).
2. **Contrato/integração (borda — tools + agent fake da SDK)**
   - Cada tool: total formatado pt-BR; mês corrente (fuso SP) quando ausente; repassa
     filtros; **escopada** (a tool de um usuário não enxerga dados de outro); schema correto.
   - Tool registra `payload` + `trace` no coletor.
   - `ResponderConsulta` (com `Ai::fakeAgent`): chama a tool e aprova só com números do
     payload; encaminha a pergunta íntegra; **regenera** descartando a divergente; cai no
     **fallback** após esgotar; aprova resposta sem números mesmo sem tool; bloqueia número
     inventado com payload vazio; expõe as 4 tools amarradas ao usuário e ao coletor.
   - **Conjunto-verdade combinado (C7):** o guard aprova um texto cujos valores vêm de
     **múltiplas** tools (gastos de um mês + disponível de outro, cada valor exclusivo de uma
     tool), validando contra `coletor->payloadCombinado()`; contraprova: contra o payload de
     uma tool só, o texto **reprova**. *Obs.: o `FakeTextGateway` da SDK executa no máximo
     uma tool por turno do agente, então a combinação é exercitada no seu seam real (duas
     tools reais + coletor único + guard real), não via `Ai::fakeAgent`.*
   - Registro de uso em `ai_usage_log` (uma linha por geração).

> Cada item de backend só é "feito" com **testes verdes e cobertura**. A camada de IA usa os
> **fakes da Laravel AI SDK** (`Ai::fakeAgent`/`assertAgentWasPrompted`) — offline e determinístico.

## 8. Backend agora · Frontend depois
| Backend (esta etapa) ✅ | Frontend (etapa separada e posterior) |
|---|---|
| 4 tools com escopo por usuário + trace | Apresentação das respostas no bot do Telegram (mensagens curtas, sem botões) |
| Agente `AssistenteDeConsulta` (SDK) | Tela de chat / exibição das fontes na web |
| Coletor + guard pós-geração + `ResponderConsulta` (regenera/fallback) | Mensagem de "instabilidade, tentando novamente" / degradação para comandos |
| `RespostaDaConsulta` com fontes/trace (barreira 5) | — |

## 9. Definition of Done
- [x] Cenários de §3 cobertos por testes que falhavam antes e agora passam.
- [x] Barreiras de §4 garantidas (teste do guard, do escopo por usuário e do determinismo de "agora").
- [x] Sem segredo/PDF/dado sensível persistido ou commitado (tools somente-leitura; `ai_usage_log` só metadados).
- [x] Commit local atômico, em português, separando backend de frontend.
- [x] §10 preenchida com os artefatos reais.

## 10. Estado atual / artefatos
- **Status:** ✅ Concluído (backend). Apresentação = **frontend**, adiada.
- **Entregue:**
  - **Tools:** `app/Ai/Tools/ConsultarGastos.php`, `ConsultarDisponivelMes.php`,
    `ConsultarProximasContas.php`, `ConsultarFaturaCartao.php`.
  - **Agente:** `app/Ai/Agents/AssistenteDeConsulta.php` (trait `App\Ai\Concerns\UsaFailoverDeProvedores`).
  - **Orquestração:** `app/Domain/IA/Consulta/ResponderConsulta.php`, `ColetorDeConsultas.php`,
    `RespostaDaConsulta.php`, `TraceDaConsulta.php`.
  - **Guard:** `app/Domain/IA/Guard/GuardPosGeracao.php`, `PayloadDeResposta.php`, `ResultadoDoGuard.php`.
  - **Camadas de consulta:** `app/Domain/Gastos/ConsultarGastos.php` (+ `ResultadoConsultaGastos`),
    `app/Domain/Disponivel/ConsultarDisponivelDoMes.php` (+ `ResultadoConsultaDisponivel`),
    `app/Domain/ProximasContas/ConsultarProximasContas.php` (+ `ResultadoConsultaProximasContas`),
    `app/Domain/FaturaCartao/ConsultarFaturaCartao.php` (+ `ResultadoConsultaFaturaCartao`).
  - **Custo de IA:** uma linha em `ai_usage_log` por geração via
    `app/Domain/IA/Custo/RegistrarUsoDeIA.php` + `CalculadoraDeCustoIA.php`.
  - **Testes:** `tests/Unit/Domain/GuardPosGeracaoTest.php`, `ColetorDeConsultasTest.php`;
    `tests/Feature/AI/ResponderConsultaTest.php` (inclui o C7 — guard sobre o conjunto-verdade
    **combinado** de várias tools, com contraprova contra uma só), `ConsultarGastosToolTest.php`,
    `ConsultarDisponivelMesToolTest.php`, `ConsultarProximasContasToolTest.php`,
    `ConsultarFaturaCartaoToolTest.php`, `ToolColetaPayloadTest.php`, `RegistroDeUsoNaConsultaTest.php`;
    `tests/Feature/Domain/ConsultarGastosTest.php`, `ConsultarDisponivelDoMesTest.php`,
    `ConsultarProximasContasTest.php`, `ConsultarFaturaCartaoTest.php`.
- **Adiado para:** **frontend** (apresentação no bot/web, item "(Etapa separada)" do
  Bloco 5 em `docs/TODO.md`); registro de uso de importação/resumo → [[spec-11-importacao-pdf]].
- **Decisões de regra tomadas:**
  - O **guard é determinístico** e parseia o texto pt-BR de volta a centavos; **contagens
    não-monetárias** (sem "R$"/vírgula decimal) são ignoradas de propósito.
  - **MAX_TENTATIVAS = 2**: esgotadas, **fallback sem números** em vez de arriscar valor alucinado.
  - **Cartão/categoria não encontrados → consulta vazia** (nunca erro que vaze existência de
    dados de terceiros); escopo sempre por `user_id`.
  - **"Próximas contas"** exclui `pago` (não é mais conta a pagar); **"fatura"** é extrato e
    **mantém** `pago`; ambos atribuem cobrança ao mês de **vencimento** (§4.5).
  - **Cada geração** (incluindo regenerações) gera uma linha em `ai_usage_log`.
