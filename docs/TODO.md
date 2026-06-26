# TODO completo para iniciar o desenvolvimento

> Fonte de verdade: seção 18 do escopo final.

## Como usar este TODO

Sequência prática, **sempre test-first**. Cada item de backend só é dado como concluído com **testes passando e cobertura**. O frontend correspondente é tarefa separada, executada depois — marcado abaixo como **(Etapa separada)**.

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

## Bloco 3 — Telegram 🟡 em andamento

- [x] Testes + implementação: vínculo (token único), middleware de autenticação, dedupe por `update_id`. _(schema `telegram_links` (token só em hash + expiração; partial unique: 1 ativo por conta / 1 telegram_user_id ativo) e `telegram_updates` (unique `update_id`); `app/Domain/Telegram/`: `GerarTokenDeVinculo`, `VincularTelegram`, `AutenticarTelegram`, `DedupeDeUpdate` (insertOrIgnore). Webhook ligando segredo+dedupe+autenticação: `app/Http/Controllers/TelegramWebhookController.php` (adaptador fino, sempre 200), `app/Http/Middleware/VerificaSegredoTelegram.php` (header `X-Telegram-Bot-Api-Secret-Token`, CSRF isento), ponto de extensão `app/Domain/Telegram/RoteadorDeMensagem.php` + default inerte `RoteadorInerte`)_
- [x] Testes + implementação: roteamento de comandos (registrar/editar/cancelar/buscar). _(roteamento **determinístico**, sem IA/sem FE: `app/Domain/Telegram/` — `Comando` (enum de intenção), `ComandoRecebido` (VO), `ClassificadorDeComando` (texto → intenção + argumentos brutos por slash-command/`@bot`/palavra-chave inicial; texto livre → DESCONHECIDO preservando o texto íntegro para a IA), `ManipuladorDeComando` (ponto de extensão por intenção) + `ManipuladorInerte`, `RoteadorDeComandos` (despacha por mapa de manipuladores, fallback no padrão; `naoVinculado` no-op). `RoteadorInerte` removido; rebind em `AppServiceProvider`. **Adiado:** execução de cada comando = extração via IA + confirmação (Bloco 4) e ligação ao domínio financeiro; mensagens do bot e vínculo via bot = FE)_
- [ ] **(Etapa separada)** Mensagens formatadas do bot (curtas, sem botões).

## Bloco 4 — IA de interpretação (Laravel AI SDK)

- [ ] Instalar e configurar a Laravel AI SDK (`laravel/ai`); publicar `config/ai.php` e migrations; definir provedor padrão (Anthropic) e array de failover.
- [x] Testes + implementação: Agents (`make:agent`) de intenção, extração e redação. _(`app/Ai/Agents/`: `ClassificadorDeIntencao` (papel 1, structured → enum `App\Domain\IA\Intencao`, fallback DESCONHECIDO), `ExtratorDeGasto` (papel 2, structured → `ResultadoDaExtracao`/`GastoExtraido` **crus**: valor/data como texto, IA não normaliza; barreira 1 = campo obrigatório ausente vira esclarecimento; crédito exige cartão §3.4), `RedatorDeResposta` (papel 3, texto a partir do payload já calculado). Testado com os fakes da SDK (`Ai::fakeAgent`/`assertAgentWasPrompted`), determinístico e offline. **Adiado:** normalização determinística + confirmação (item 3); guard pós-geração (barreira 4) no Bloco 6)_
- [ ] Testes + implementação: extração via `HasStructuredOutput` (schema validado) com confirmação.
- [ ] Testes + implementação: Tools (`make:tool`) de consulta com escopo por usuário + guard pós-geração.
- [ ] Testes + implementação: histórico via `RemembersConversations` (expurgo 60 dias), `ai_usage_log` e failover. Usar fakes da SDK nos testes.

## Bloco 5 — Importação de PDF

- [ ] Testes + implementação: recepção, validação de nome, bloqueio de PDF com senha.
- [ ] Testes + implementação: extração de texto + OCR fallback (Tesseract) — efêmero.
- [ ] Testes + implementação: parser Itaú, pré-importação, duplicidade, descarte de PDF/texto.
- [ ] Testes + implementação: `pdf_parse_errors` para evolução do parser.
- [ ] **(Etapa separada)** Tela web de revisão em lote + resumo no bot.

## Bloco 6 — Chat financeiro

- [ ] Testes + implementação: ferramentas de consulta com escopo por usuário.
- [ ] Testes + implementação: guard pós-geração (nenhum número fora do payload).
- [ ] Testes + implementação: resposta com fonte/trace.
- [ ] **(Etapa separada)** Apresentação das respostas no bot/web.

## Bloco 7 — Dashboard e fechamento do MVP

- [ ] Testes + implementação: agregações do mês (gastos, próximas contas, cartão atual).
- [ ] Job de expurgo de mensagens (60 dias).
- [ ] **(Etapa separada)** Telas e gráficos do dashboard.
