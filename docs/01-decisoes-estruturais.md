# 01 · Decisões Estruturais (stack e fundações)

> _Ver seção 2 do escopo._

Estas decisões são a base técnica do projeto. São recomendações justificadas, coerentes
com o domínio financeiro e com a separação de responsabilidades exigida (IA nunca calcula
dinheiro). O desenvolvedor pode ajustar, mas elas estão fechadas para o MVP.

## Tabela de decisões

| Área | Decisão | Por quê |
|---|---|---|
| **Linguagem / framework** | PHP 8.3 + Laravel 12 | Produtividade, filas (Horizon), testes (Pest/PHPUnit), ecossistema maduro. |
| **Banco relacional** | PostgreSQL 16 | Precisão financeira, constraints fortes, `NUMERIC` nativo, suporta pgvector se necessário. Recomendado sobre MySQL para este caso. |
| **Representação de dinheiro** | `BIGINT` em centavos (inteiro) | Elimina erro de ponto flutuante. Determinístico. Exibição formatada em pt-BR na borda. |
| **Fuso horário base** | `America/Sao_Paulo` | Datas relativas (hoje/ontem/amanhã) e vencimentos sempre nesse fuso. |
| **Fila / idempotência** | Fila com driver `database` (MVP); idempotência por unique constraint | Evita Redis no MVP. Dedupe de mensagem do Telegram por `update_id` único no banco. Driver trocável por Redis via env ao escalar. |
| **Processamento assíncrono** | Laravel Queues (worker dedicado) | PDF e chamadas de IA fora do request. Horizon entra ao migrar a fila para Redis. |
| **Canal Telegram** | Webhook no próprio app + adaptador isolado | Sem contêiner extra no MVP. O adaptador permite extrair o bot para serviço separado (e WhatsApp futuro) sem tocar na regra. |
| **Biblioteca de IA** | **Laravel AI SDK (`laravel/ai`)** — pacote first-party | TODA a IA usa a biblioteca oficial do Laravel 12: Agents, tools, structured output, memória de conversa, failover entre provedores e fakes para teste. Sem cliente HTTP próprio. |
| **Provedor de IA** | Agnóstico via SDK; **Anthropic Claude** como padrão | Troca de provedor por 1 linha / `.env`; failover nativo. Tool use + structured output forçam saída estruturada. |
| **Banco vetorial** | pgvector (extensão do Postgres), só quando necessário | Evita infra extra. **NÃO entra no MVP** — ver veredito de RAG (seção 16). |
| **OCR** | Camada de texto primeiro; **Tesseract como biblioteca no worker** | PDF com texto selecionável dispensa OCR. Tesseract roda dentro do worker (não é contêiner). |
| **Observabilidade** | Logs estruturados + `ai_usage_log` (MVP) | Cobre erro e custo de IA sem infra extra. Prometheus + Grafana entram no perfil de produção ao escalar. |
| **Containerização** | Docker + `docker compose` (**3 contêineres** no MVP) | Ambiente reprodutível. Perfil de produção e caminho de escala na seção 12. |
| **Execução** | **100% em contêiner** — nada instalado localmente (exceto `make`) | composer, artisan, php, node, testes: tudo via `docker compose exec` / Makefile. Idêntico em qualquer máquina. |
| **Orquestração em produção** | **Docker Swarm** | Gerencia os contêineres em produção. O mesmo desenho do compose evolui para stack do Swarm. |
| **Segredos** | **Docker Secrets** em produção — **SEM `.env`** | Chaves montadas em `/run/secrets`. `.env` existe apenas em desenvolvimento (não versionado) + `.env.example`. |

## Exclusões estruturais (decisões de "não fazer")

- **PDF e texto extraído NUNCA são persistidos.** O processamento é efêmero
  (memória/worker) e descartado ao final.
- **Nenhum dado sensível da fatura é armazenado** (nome, endereço, CPF, data de
  nascimento). Apenas lançamentos financeiros **não sensíveis** viram registros.
- **Exclusão de dados é lógica (soft delete)** seguindo a LGPD; o histórico de auditoria
  é preservado.
- **Sem armazenamento de comprovantes em imagem** no MVP.

> **Consequência:** como o PDF e o texto nunca são retidos, não há corpus para vetorizar.
> Isso esvazia a necessidade de RAG documental (ver seção 16 do escopo).
