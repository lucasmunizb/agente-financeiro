# TODO completo para iniciar o desenvolvimento

> Fonte de verdade: seção 18 do escopo final.
>
> **Trabalhamos orientados a spec.** Cada Bloco abaixo tem um spec autocontido (ponto de
> partida para implementar test-first) em [`docs/specs/`](specs/README.md). Este TODO é o
> checklist de progresso; o spec é o "como começar".

## Como usar este TODO

Sequência prática, **sempre test-first**. Cada item de backend só é dado como concluído com **testes passando e cobertura**. O frontend correspondente é tarefa separada, executada depois — marcado abaixo como **(Etapa separada)**.

> **Todo o frontend** (telas web + mensagens do bot) está **consolidado** na fase **FE**:
> [`docs/specs/FE-frontend-stitch.md`](specs/FE-frontend-stitch.md) — um prompt de Stitch por
> tela + design system. Executada após o backend das features. Os itens **(Etapa separada)**
> abaixo apontam para lá.

## Bloco 0 — Fundações e DevOps ✅

- [x] Rodar o bootstrap criando tudo por contêiner (esqueleto Laravel via contêiner; docker compose com `app`, `worker` e `postgres`; Makefile encapsulando `docker compose exec`). Nada instalado localmente além do `make`.
- [x] Configurar PostgreSQL, fila no driver `database`, worker dedicado, scheduler e healthchecks.
- [ ] Configurar logs estruturados e `ai_usage_log` (sem dados sensíveis). Deixar `QUEUE_CONNECTION` e provedores trocáveis por env. _(`ai_usage_log` fica para o Bloco 4)_
- [x] Preparar produção: `docker-stack.yml` (Docker Swarm) com Docker Secrets em `/run/secrets` e entrypoint que os carrega; sem `.env` em produção (`.env` só em dev).
- [x] Documentar o caminho de escala (Redis/Horizon, bot dedicado, Prometheus/Grafana) como perfil de produção, sem implementar agora.
- [x] Criar `CLAUDE.md`, docs separadas e as skills iniciais: `skill-creator`, `laravel-backend` e `devops` (as de IA/bot e frontend ficam para depois, criadas com a `skill-creator`).
- [x] Definir regra: a IA nunca faz push no GitHub; commits locais apenas, sem operações em remoto sem ordem explícita.

## Bloco 1 — Domínio financeiro (test-first) ✅

- [x] Testes + implementação: representação monetária (centavos inteiros) e formatação pt-BR. _(`app/Domain/Shared/Money.php`)_
- [x] Testes + implementação: resolução de datas relativas (fuso SP). _(`app/Domain/Calendar/RelativeDate.php`)_
- [x] Testes + implementação: cartões, contas, formas de pagamento, status (tabela de referência). _(migrations + models + ajustes em `users`)_
- [x] Testes + implementação: parcelas e geração de parcelas futuras (regras da seção 4.1). _(`app/Domain/Parcelamento/GeradorDeParcelas.php`)_
- [x] Testes + implementação: vencimentos (cartão vs. fora de cartão). _(`app/Domain/Vencimento/CalculadoraDeVencimento.php`)_
- [x] Testes + implementação: fórmula do disponível do mês. _(`app/Domain/Disponivel/DisponivelDoMes.php`)_
- [x] Testes + implementação: detecção de duplicidade. _(`app/Domain/Duplicidade/DetectorDeDuplicidade.php`)_

## Bloco 2 — Cadastro manual e receitas ✅ (backend; frontend é etapa separada)

- [x] Testes + implementação: CRUD de gastos com status, origem e auditoria. _(`app/Domain/Gasto/`: `RegistrarGastoManual` (criar), `EditarGastoManual` (regenera parcelas; bloqueia se houver parcela paga), `CancelarGastoManual` (status `cancelado`, preserva pagas, mantém linha), `ExcluirGastoManual` (soft delete LGPD); montagem de parcelas extraída em `MontadorDeParcelas`; auditoria criar/editar/cancelar/excluir)_
- [x] Testes + implementação: categorias + palavras-chave + aliases. _(schema `categories`/`category_keywords`/`merchant_aliases` + FK em `transactions`; lookup determinístico `app/Domain/Categoria/` — classificador, lookup e aprendizado por correção)_
- [x] Testes + implementação: receitas e orçamento mensal geral + alerta por categoria. _(schema `incomes`/`budgets`; `app/Domain/Receita/ReceitasDoMes`, `app/Domain/Orcamento/` — `Orcamento` puro, `ConsumoDoMes` (total + por categoria), `OrcamentoMensal`; `Shared/PeriodoMensal`. **Alerta por categoria**: decidido entregar só o consumo por categoria; disparo/limiar fica pós-MVP — ver doc 04/08)_
- [ ] **(Etapa separada)** Frontend web de gastos, categorias, receitas e orçamento.

## Bloco 3 — Telegram ✅ (backend; frontend → fase FE)

- [x] Testes + implementação: vínculo (token único), middleware de autenticação, dedupe por `update_id`. _(schema `telegram_links` (token só em hash + expiração; partial unique: 1 ativo por conta / 1 telegram_user_id ativo) e `telegram_updates` (unique `update_id`); `app/Domain/Telegram/`: `GerarTokenDeVinculo`, `VincularTelegram`, `AutenticarTelegram`, `DedupeDeUpdate` (insertOrIgnore). Webhook ligando segredo+dedupe+autenticação: `app/Http/Controllers/TelegramWebhookController.php` (adaptador fino, sempre 200), `app/Http/Middleware/VerificaSegredoTelegram.php` (header `X-Telegram-Bot-Api-Secret-Token`, CSRF isento), ponto de extensão `app/Domain/Telegram/RoteadorDeMensagem.php` + default inerte `RoteadorInerte`)_
- [x] Testes + implementação: roteamento de comandos (registrar/editar/cancelar/buscar). _(roteamento **determinístico**, sem IA/sem FE: `app/Domain/Telegram/` — `Comando` (enum de intenção), `ComandoRecebido` (VO), `ClassificadorDeComando` (texto → intenção + argumentos brutos por slash-command/`@bot`/palavra-chave inicial; texto livre → DESCONHECIDO preservando o texto íntegro para a IA), `ManipuladorDeComando` (ponto de extensão por intenção) + `ManipuladorInerte`, `RoteadorDeComandos` (despacha por mapa de manipuladores, fallback no padrão; `naoVinculado` no-op). `RoteadorInerte` removido; rebind em `AppServiceProvider`. **Adiado:** execução de cada comando = extração via IA + confirmação (Bloco 4) e ligação ao domínio financeiro; mensagens do bot e vínculo via bot = FE)_
- [ ] **(Etapa separada → fase FE)** Mensagens formatadas do bot (curtas, sem botões) + tela web de vínculo do Telegram.

## Bloco 4 — IA de interpretação (Laravel AI SDK)

- [x] Instalar e configurar a Laravel AI SDK (`laravel/ai`); publicar `config/ai.php` e migrations; definir provedor padrão (Anthropic) e array de failover. _(`laravel/ai ^0.8.1`; `config/ai.php` + `config/ai_custos.php` publicados; migration `agent_conversations`; `ai.default=anthropic`; `ai.failover` (array, env `AI_FAILOVER`))_
- [x] Testes + implementação: Agents (`make:agent`) de intenção, extração e redação. _(`app/Ai/Agents/`: `ClassificadorDeIntencao` (papel 1, structured → enum `App\Domain\IA\Intencao`, fallback DESCONHECIDO), `ExtratorDeGasto` (papel 2, structured → `ResultadoDaExtracao`/`GastoExtraido` **crus**: valor/data como texto, IA não normaliza; barreira 1 = campo obrigatório ausente vira esclarecimento; crédito exige cartão §3.4), `RedatorDeResposta` (papel 3, texto a partir do payload já calculado). Testado com os fakes da SDK (`Ai::fakeAgent`/`assertAgentWasPrompted`), determinístico e offline. **Adiado:** normalização determinística + confirmação (item 3); guard pós-geração (barreira 4) no Bloco 5)_
- [x] Testes + implementação: extração via `HasStructuredOutput` (schema validado) com confirmação. _(ponte **determinística** cru→confirmação, IA não participa: `app/Domain/IA/NormalizadorDeGastoExtraido` converte `GastoExtraido` → `DadosGastoManual` resolvendo valor→centavos (`Money`), data→fuso SP (`RelativeDate` + dd/mm[/aaaa] + aaaa-mm-dd), forma→`PaymentMethod::idFor`, cartão→id (só crédito, casado por token na descrição; 0/≥2 → esclarecimento), categoria via `LookupDeCategoria`; o que não resolve vira esclarecimento §3.4 (`ResultadoDaNormalizacao`). `PrepararConfirmacaoDeGasto` gera a `PreviaGastoManual` via `RegistrarGastoManual::preview()` **sem persistir** (regra 7) e carrega o `DadosGastoManual` para o "sim" (reusa `confirmar()`) — `ConfirmacaoDeGasto`. **Boleto** virou forma de 1ª classe ("fora de cartão"): model/seeder/migration do CHECK + docs 03§4.6/04 alinhados. **Adiado:** amarração `classificar→extrair→confirmar` ao roteador do Telegram e a mensagem de confirmação do bot = F5/FE)_
- [x] Testes + implementação: Tools (`make:tool`) de consulta com escopo por usuário + guard pós-geração. _(4 tools em `app/Ai/Tools/`; guard e orquestração no Bloco 5)_
- [x] Testes + implementação: histórico via `RemembersConversations` (expurgo 60 dias), `ai_usage_log` e failover. Usar fakes da SDK nos testes. _(**Expurgo**: `app/Domain/IA/Historico/ExpurgarConversas` apaga conversas+mensagens com `updated_at` além de 60 dias ("agora" injetado, fuso SP — determinismo regra 4/5); comando `ai:expurgar-conversas` agendado diário 03:30. **Custo**: tabela `ai_usage_log` (append-only, sem dado sensível, custo em centavos BIGINT) + `AiUsageLog` + enum `TipoDeUsoIA` (mensagem/importacao/resumo) + `UsoDeIA` (VO) + `RegistrarUsoDeIA` (recorder) + `CalculadoraDeCustoIA` (tokens × `config/ai_custos.php` → centavos); ligado em `ResponderConsulta` (1 linha por geração: tipo mensagem, escopo por usuário, provider/model/tokens/latência da resposta da SDK). **Failover**: `config('ai.failover')` (array, env `AI_FAILOVER`) exposto pelos 4 agentes via trait `UsaFailoverDeProvedores::provider()` → failover nativo da SDK; listener `LogarFailoverDeIA` no evento `AgentFailedOver` loga a instabilidade sem dado sensível. **Adiado:** mensagem "instabilidade, tentando novamente" + re-enfileirar/degradar p/ comandos = F5/FE; registro de uso de importação/resumo entra com os Blocos 5/7)_

## Bloco 5 — Chat financeiro

- [x] Testes + implementação: ferramentas de consulta com escopo por usuário (doc 02 §3.2).
  - [x] `consultar_gastos` (periodo, categoria?, cartao?, status?).
  - [x] `consultar_disponivel_mes` (mes).
  - [x] `consultar_proximas_contas` (janela em dias; contas a vencer a partir de hoje).
  - [x] `consultar_fatura_cartao` (cartao, competencia).
- [x] Testes + implementação: guard pós-geração (nenhum número fora do payload). _(`GuardPosGeracao` + orquestração `app/Domain/IA/Consulta/ResponderConsulta`: agente `AssistenteDeConsulta` chama as tools, o `ColetorDeConsultas` reúne o conjunto-verdade (payload + trace), o guard valida cada número/data; divergência regenera, esgotou cai em fallback sem números. `PayloadDeResposta::combinar` funde os payloads das tools chamadas)_
- [x] Testes + implementação: resposta com fonte/trace. _(`RespostaDaConsulta` carrega as `fontes` (trace de cada tool) — barreira 5; apresentação = FE)_
- [ ] **(Etapa separada)** Apresentação das respostas no bot/web.

## Bloco 6 — Dashboard

- [ ] Testes + implementação: agregações do mês (gastos, próximas contas, cartão atual).
- [ ] Job de expurgo de mensagens (60 dias).
- [ ] **(Etapa separada)** Telas e gráficos do dashboard.

> **Importação de PDF removida do MVP.** A importação de fatura (alto valor, alto risco) saiu
> do MVP e virou a **1ª etapa do pós-MVP** — ver **Pós-MVP · Importação de PDF** no fim deste
> arquivo e [`ROADMAP-POS-MVP.md`](ROADMAP-POS-MVP.md) (ordem 1).

## Bloco 8 — Segurança e LGPD (portão de fechamento)

> Spec: [`docs/specs/08-seguranca-lgpd.md`](specs/08-seguranca-lgpd.md). **Transversal**:
> roda ao final, depois das features. Acione a skill `seguranca-ia`.

- [ ] Code review de segurança (webhook, escopo por usuário, segredos, retenção, borda de IA, auditoria).
- [ ] Pen test (webhook 403/200 + idempotência, comando sem vínculo, auditoria de segredos no repo/imagem/log).
- [ ] Testes adversariais de prompt (determinísticos): guard sob injeção, tools ignoram `user_id` injetado, classificação defensiva, saída sem trace, sem escrita sem confirmação.
- [ ] Hardening: bloco "Segurança" no `instructions()` de todos os agentes; delimitar texto não confiável.
- [ ] Conformidade LGPD (consentimento, minimização, retenção, exclusão lógica, transparência de IA).
- [ ] **Enumeração de contas no registro (pentest L1/2026-07 — deferido).** Hoje `/criar-conta` revela "e-mail já cadastrado" (oráculo de existência) e tem diferença de timing; mitigado por `throttle:auth`. **Correção real exige fluxo de verificação por e-mail** (aceitar sempre + avisar o dono por e-mail; resposta neutra ao cliente) — depende de infra de e-mail transacional, ausente no MVP. Decidir com o usuário quando houver e-mail. Ver `docs/pentest-2026-07-14.md` (achado L1).
- [ ] **(Etapa separada)** Mensagens de recusa do bot, aviso de transparência de IA e fluxo de consentimento.

> **Pentest 2026-07 aplicado** (`docs/pentest-2026-07-14.md`): 14 de 15 achados corrigidos com
> testes de regressão (M1 re-auth na exclusão, M2 guard anti-alucinação, M3 esquecimento do texto
> livre, L2–L12 + L6 CSP `style-src` sem `unsafe-inline`). Resta só L1 (enumeração no registro),
> acima. Sem achados Crítico/Alto; IDOR/SQLi/mass-assignment/webhook/upload limpos.

## Bloco 9 — Faturas materializadas 🟠 (pendente — proposta a validar)

> Spec: [`docs/specs/09-faturas-materializadas.md`](specs/09-faturas-materializadas.md).
> **Não iniciar:** hoje a fatura é derivada (sem tabela `invoices`). Proposta de materializá-la
> (com `data_pagamento`, status e vínculo parcela↔fatura). **Fechar as Questões em aberto (§4b)
> antes de escrever testes** — em especial vínculo N:1 vs N:N e valor derivado vs snapshot.

- [ ] Decidir §4b (Q1–Q5) do spec 09 com o usuário.
- [ ] Testes + implementação: tabela `invoices` + vínculo parcela↔fatura + find-or-create.
- [ ] Testes + implementação: pagamento da fatura (boleto §4.5) sem regredir o disponível do mês.
- [ ] **(Etapa separada)** Tela/mensagem de fatura e destaque de divergência importada.

---

## Bloco 13 — Quitar conta em qualquer superfície ✅

> Spec: [`docs/specs/13-quitar-conta.md`](specs/13-quitar-conta.md).
> Marcar pago existia, mas nunca no lugar em que o usuário percebe que precisa fazê-lo — e não
> tinha volta. Agora extrato, dashboard e bot têm a ação; o pagamento é reversível.

- [x] Testes + implementação: `ReverterPagamentoParcela` / `ReverterPagamentoOcorrencia`
      (status de volta derivado da data; corrige a agregação "zero pagas → pago_parcial").
- [x] Testes + implementação: rota de edição da ocorrência (`EditarOcorrencia` estava órfão).
- [x] Testes + implementação: alvos de ação no extrato e nos quadros do dashboard, sem vazar id
      para o payload da IA.
- [x] Testes + implementação: intenção `pagar` no bot (`ResolverContaAPagar` determinístico;
      a IA extrai só o termo).
- [x] **(Etapa separada)** Dois botões na linha do extrato, ações na linha do dashboard,
      redação do bot.
- [ ] **Pendente:** golden set do classificador para a fronteira `registrar` × `pagar`
      ("paguei 40 no mercado" vs "paguei a luz") — hoje separados só pelo prompt, e os fakes da
      SDK não exercitam o modelo real.
- [ ] **Bloqueado pelo Bloco 9:** quitar a **fatura do cartão** inteira (hoje a fatura é derivada).

---

## Pós-MVP · Importação de PDF (1ª etapa) — ex-Bloco 7

> Spec: [`docs/specs/11-importacao-pdf.md`](specs/11-importacao-pdf.md).
> **Removida do MVP** (alto valor, alto risco): é a **1ª evolução após o fechamento do MVP**
> ([`ROADMAP-POS-MVP.md`](ROADMAP-POS-MVP.md), ordem 1). O pipeline efêmero base já está pronto
> e testado; falta a regra de extração do `ParserItau` + as telas.

- [ ] Testes + implementação: recepção, validação de nome, bloqueio de PDF com senha.
- [ ] Testes + implementação: extração de texto + OCR fallback (Tesseract) — efêmero.
- [ ] Testes + implementação: parser Itaú, pré-importação, duplicidade, descarte de PDF/texto.
- [ ] Testes + implementação: `pdf_parse_errors` para evolução do parser.
- [ ] **(Etapa separada)** Tela web de revisão em lote + resumo no bot.
