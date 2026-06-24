# 02 · Governança de IA e Determinismo

> Fonte de verdade: **Seção 3** do escopo final (`gestao_contas_ia_ESCOPO_FINAL`).
> Controle central de risco do produto: como impedir que a IA invente dados, como validar suas respostas e quando consultar o banco em vez de calcular.

## Princípio inegociável

Toda informação monetária — valores, saldos, parcelas, vencimentos, "disponível do mês" — é **calculada de forma determinística** (SQL / motor financeiro testado). **A IA nunca calcula dinheiro:** ela interpreta, classifica e formata respostas sobre números que o sistema já calculou.

---

## 3.1 · Os três únicos papéis da IA

A IA atua em três papéis e **somente neles**:

| Papel | Entrada | Saída | Pode calcular dinheiro? |
|-------|---------|-------|--------------------------|
| **Classificar intenção** | Texto do usuário | Intenção (registrar / consultar / editar / cancelar / importar) | **Não** |
| **Extrair campos** | Texto do usuário | JSON estruturado validado por schema (tool use) | **Não** |
| **Redigir resposta** | Dados já calculados pelo motor financeiro | Texto em linguagem natural | **Não** — apenas formata números recebidos |

### Regra de ouro

> A IA **nunca** produz um número que não tenha vindo de uma consulta determinística do sistema. Ela não "decide o saldo"; ela aciona ferramentas e **explica** o resultado calculado.

---

## 3.2 · Padrão tool use (ferramentas com escopo por usuário)

A IA **não executa SQL livre**. Ela só pode chamar ferramentas pré-definidas, parametrizadas e **sempre filtradas pelo usuário autenticado**:

| Ferramenta | Parâmetros |
|------------|------------|
| `consultar_gastos` | `periodo, categoria?, cartao?, status?` |
| `consultar_disponivel_mes` | `mes` |
| `consultar_proximas_contas` | `janela` |
| `consultar_fatura_cartao` | `cartao, competencia` |

Cada ferramenta executa consulta parametrizada no banco **do usuário** e devolve **dados + um "trace"** (período, filtros, contagem de registros). A IA recebe esses dados prontos e **apenas redige**.

---

## 3.3 · As 5 barreiras anti-alucinação (camadas)

| # | Camada | Mecanismo |
|---|--------|-----------|
| 1 | **Saída estruturada** | Extração via tool use com schema JSON. Campo ausente vira **pergunta** ao usuário, nunca "chute". |
| 2 | **Confirmação** | No MVP, **todo** registro/edição exige confirmação antes de persistir (Telegram confirma; web revisa). |
| 3 | **Ferramentas com escopo** | IA só lê dados via ferramentas filtradas por usuário. Sem SQL livre. **Sem escrita direta pela IA.** |
| 4 | **Guard pós-geração** | Antes de enviar, o sistema extrai todos os valores monetários e datas do texto da IA e valida que **cada um existe no payload calculado**. Divergência → **bloqueia** e regenera/usa fallback. |
| 5 | **Fonte e explicação** | Toda resposta carrega a fonte (período, filtros, registros) e explica como o valor foi obtido. |

---

## 3.4 · Validação determinística de extração

- **Soma das parcelas** deve bater com o valor total informado; caso contrário, pergunta de esclarecimento.
- Forma de pagamento **"crédito" exige cartão identificado** (texto exato salvo, ex.: "cartão pai"). Sem identificação → o bot pergunta.
- **Datas relativas** resolvidas por regra fixa no fuso de **São Paulo**: hoje; ontem = `-1`; amanhã = `+1`; mês que vem = dia `05` do próximo mês.
- **Moeda** assumida sempre **BRL** quando não informada; centavos/abreviações ("35 conto") normalizados para **centavos inteiros**.

---

## 3.5 · Confiança e auto-save

A confirmação é **sempre** exigida no MVP. O auto-save de alta confiança só é habilitado quando, **por intenção**, as últimas **100 interações** tiverem **≥ 95% sem correção** (métrica móvel persistida).

| Métrica | Definição |
|---------|-----------|
| **Acurácia móvel por intenção** | % das últimas 100 interações daquela intenção que **não** receberam correção do usuário. |
| **Gatilho de auto-save** | Acurácia **≥ 95%** naquela intenção. Abaixo disso, **sempre confirmar**. |
| **Sinal de correção** | Mensagens como "errou", "não é isso", "corrigir", "correção", ou edição manual na web. |

---

## 3.6 · Histórico de conversa, custo e fallback

| Pergunta | Recomendação |
|----------|--------------|
| **Histórico de conversa** | Via `RemembersConversations` da Laravel AI SDK (tabelas `agent_conversations` / `agent_conversation_messages`), com **expurgo de 60 dias**. Usado para contexto, completar mensagens incompletas e auditoria. |
| **Custo de IA** | Tabela `ai_usage_log` por chamada: modelo, tokens in/out, custo estimado, latência, tipo (mensagem/importação/resumo), usuário. Consultável por SQL/admin. |
| **Fallback** | Failover nativo da SDK (array de provedores). Em indisponibilidade total: mensagem ao usuário ("instabilidade, tentando novamente"), **re-enfileirar**; degradar para parsing por comandos. **Nunca persistir sem confirmação.** |
| **Mensagem incompleta** | Não registra nada. Responde o que falta e guarda o **rascunho de intenção**; mescla com a próxima mensagem. |
| **Mensagem ambígua / duplicada** | Sempre perguntar; em duplicidade por instabilidade, **manter a primeira** (dedupe por `update_id` do Telegram). |

---

## 3.7 · Implementação com a Laravel AI SDK (`laravel/ai`)

Toda a camada de IA é implementada pela biblioteca **oficial first-party** do Laravel. **Nada de cliente HTTP próprio** para provedores. Mapeamento direto:

| Conceito do produto | Recurso nativo da Laravel AI SDK |
|---------------------|----------------------------------|
| Os 3 papéis da IA | Três **Agents** dedicados (`make:agent`): intenção, extração e redação. |
| Extração estruturada (JSON validado) | Interface `HasStructuredOutput` com schema fluente — saída tipada e validada. |
| Tool use com escopo por usuário | **Tools** (`make:tool`): classes PHP com `handle()` + JSON schema; o modelo decide chamar, a SDK executa. Consultas sempre filtradas pelo usuário. |
| Histórico de conversa | Trait `RemembersConversations` + tabelas do SDK. Aplicar **expurgo de 60 dias**. |
| Provedor agnóstico + fallback | Enum `Lab` para escolher provedor; failover automático passando um **array de provedores**. |
| Processamento assíncrono | `queue()` / `broadcastOnQueue()` integrados às filas do Laravel (worker). |
| Anexos (PDF da fatura) | `Files` (Image/Document) no prompt; uso **efêmero** — descartar após extração (nada é persistido). |
| Testabilidade (TDD) | **Fakes** nativos (de agentes, structured output, embeddings) para testes determinísticos antes da implementação. |

### ⚠️ O guard determinístico é camada NOSSA por cima da SDK

> A SDK fornece tools e structured output, **mas não garante que um número veio do banco**. A regra "IA nunca calcula dinheiro" e o **guard pós-geração (3.3)** permanecem como **camada nossa** por cima da SDK: os tools financeiros devolvem valores **já calculados pelo domínio** e a resposta é **validada contra esse payload**.
