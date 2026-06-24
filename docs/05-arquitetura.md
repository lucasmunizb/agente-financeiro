# 05 · Arquitetura de backend e serviços

> Fonte de verdade: **seção 6** do escopo final. Stack: Laravel 12 · PHP 8.3 · PostgreSQL 16 · Docker.

## Princípio arquitetural

Arquitetura **modular, organizada por domínio (estilo DDD)**, com o processamento pesado desacoplado da API. Os **canais (Telegram/web) são bordas finas**: traduzem entrada/saída, mas **toda regra de negócio vive no domínio**. Isso permite, por exemplo, adicionar WhatsApp futuramente sem tocar na regra.

> Lembrete do produto: a IA nunca calcula dinheiro. Todo número vem do domínio financeiro determinístico; a IA interpreta, classifica e redige.

---

## Serviços / módulos

| Serviço / módulo | Responsabilidade | Notas |
|---|---|---|
| **API + canais (Laravel)** | Autenticação, regras de negócio, endpoints e webhook do Telegram | Fonte central de regras. O canal Telegram é um adaptador fino dentro do app (webhook). |
| **Domínio financeiro** | Cálculo de parcelas, vencimentos, disponível, status, duplicidade | **Determinístico e 100% testado. Núcleo do sistema.** |
| **Adaptador Telegram** | Recebe webhook, traduz mensagem/anexo e envia resposta | Isolado no código; **pode virar serviço separado** ao escalar (WhatsApp futuro). |
| **Serviço de IA (Laravel AI SDK)** | Intenção, extração estruturada, redação de resposta | Agents da `laravel/ai`. Tools com escopo por usuário. Failover nativo entre provedores. |
| **Worker** | Processa a fila (importação de PDF, chamadas de IA) e tarefas agendadas | Contêiner dedicado. **OCR (Tesseract) roda aqui como biblioteca. PDF efêmero** (descartado após processamento). |
| **Motor de cálculo financeiro** | Disponível, parcelas, vencimentos, relatórios | O mesmo do domínio, exposto como serviço testável. |
| **Notificações (pós-MVP)** | Lembretes de vencimento/orçamento | Canal Telegram no início. Fora do MVP. |
| **Banco relacional** | Fonte de verdade financeira + fila (driver `database` no MVP) | PostgreSQL. A fila migra para Redis ao escalar. |
| **Observabilidade** | Logs estruturados, `ai_usage_log`, auditoria | **Sem dados sensíveis.** Prometheus + Grafana no perfil de produção. |

---

## Fluxo conceitual: pergunta financeira

Exemplo: *"quanto gastei com comida este mês?"* (Telegram ou web)

1. **Usuário pergunta** pelo Telegram ou pela web.
2. **Serviço de IA identifica a intenção**, o período e a categoria da mensagem.
3. **IA aciona a ferramenta `consultar_gastos`** — parametrizada e sempre **filtrada pelo usuário** autenticado (sem SQL livre).
4. **Domínio executa a consulta determinística** e devolve o total + um **trace** (período, filtros, contagem de registros).
5. **Guard pós-geração valida** que todos os números/datas presentes no texto da IA existem no payload calculado. Divergência → bloqueia e regenera/usa fallback.
6. **IA redige a resposta** citando o período e os critérios (a fonte); a **auditoria é registrada sem dados sensíveis**.

> Garantia central: a IA não produz nenhum número que não tenha vindo de uma consulta determinística. Ela aciona ferramentas e explica o resultado já calculado pelo domínio.
