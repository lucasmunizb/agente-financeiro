# 09 · Requisitos não funcionais, segurança e LGPD

> Fonte de verdade: **seção 10** do Escopo Final (com apoio das seções 2, 3.6, 5 e 12.5).
> Princípio inegociável: dados financeiros são isolados por usuário, processamento sensível é efêmero e a IA só recebe dados não sensíveis.

Este documento consolida os requisitos não funcionais (NFR), de segurança e de conformidade com a LGPD que governam todo o produto. Eles valem para o MVP e para o caminho de escala (ver `docs` de DevOps).

## Tabela de requisitos

| # | Tema | Requisito | Importância |
|---|------|-----------|-------------|
| 1 | **Segurança** | Autenticação forte; validação de permissões por usuário em cada operação; **middleware de autenticação em toda mensagem do bot** (comandos só após vínculo válido — seção 7.1). | **Obrigatório** |
| 2 | **Privacidade** | Acesso restrito a dados financeiros e documentos; **minimização de dados** (só persiste lançamentos não sensíveis). | **Obrigatório** |
| 3 | **LGPD** | Consentimento (campo `aceite_lgpd_em` em `users`); finalidade definida; **transparência sobre o uso de IA**; direito de exclusão. | **Obrigatório** |
| 4 | **Criptografia** | **TLS** em toda comunicação; **avaliar criptografia em repouso** para dados sensíveis. | **Obrigatório** |
| 5 | **Segredos** | Produção: **Docker Secrets** (Swarm) em `/run/secrets`, **sem `.env`**. Dev: `.env` não versionado + `.env.example` versionado. **Chaves nunca em imagem, log ou versionamento.** | **Obrigatório** |
| 6 | **Execução** | **Tudo em contêiner**; nada instalado localmente além do `make` (composer, artisan, php, node, testes via `docker compose exec`). | **Obrigatório** |
| 7 | **Retenção** | **PDF e texto extraído: zero retenção** (processamento efêmero, descartado ao final). **Conversas: 60 dias com expurgo** (`RemembersConversations` + job de expurgo). | **Obrigatório** |
| 8 | **IA / dados** | Apenas **dados não sensíveis** vão ao provedor de IA; **isolamento por usuário** (tools sempre filtradas por `user_id`). | **Obrigatório** |
| 9 | **Performance** | Telegram responde rápido; PDF é **assíncrono** e avisa o fim por mensagem se houver telefone vinculado. | Alta |
| 10 | **Auditoria** | Histórico de criação/edição/importação/exclusão e **origem do dado** (`audit_log`, `transactions.origem`). | Alta |
| 11 | **Observabilidade** | Métricas de erro, **custo de IA** (`ai_usage_log`), tempo de importação e taxa de confirmação. | Média |
| 12 | **Disponibilidade** | **Degradar bem** se IA ou Telegram falhar (failover da SDK + fallback determinístico por comandos; nunca persistir sem confirmação). | Alta |
| 13 | **Backup** | Backups testados, com retenção definida (**apenas dados estruturados**). | Alta |
| 14 | **Exclusão** | **Soft delete lógico** conforme LGPD; **auditoria preservada**. | Alta |

## Detalhamento dos pontos críticos

### Segurança e isolamento por usuário
- Autenticação por e-mail/senha no MVP; arquitetura preparada para multiusuário.
- **Toda** mensagem do bot passa por middleware que exige vínculo válido (`telegram_user_id` + telefone verificado — seção 7.1). Sem vínculo, nenhum comando é aceito.
- A IA não executa SQL livre: só chama tools pré-definidas, parametrizadas e **filtradas pelo usuário autenticado** (seção 3.2). Sem escrita direta pela IA.

### Segredos (dev vs. produção) — seção 12.5
- **Produção (Swarm):** variáveis não sensíveis no stack; chaves (DB, provedores de IA, token do Telegram, `APP_KEY`) via **Docker Secrets** em `/run/secrets`. O entrypoint lê os segredos antes do boot; evitar `config:cache` com segredos embutidos em build.
- **Dev:** `.env` local não versionado + `.env.example` versionado.
- Padrão recomendado: variáveis `*_FILE` apontando para `/run/secrets/<nome>`.
- **Segredos nunca vão para imagem, logs ou versionamento.**

### Retenção e minimização — seções 2 e 8.3
- **PDF original:** nunca armazenado, descartado sempre.
- **Texto extraído:** não retido após o processamento.
- **Dados sensíveis da fatura** (nome, endereço, CPF, data de nascimento): **ignorados integralmente**, nunca persistidos. Só lançamentos financeiros não sensíveis viram registros.
- **Conversas:** retenção de **60 dias** com job de expurgo (`agent_conversations` / `agent_conversation_messages`).
- Sem armazenamento de comprovantes em imagem no MVP.
- Consequência: como PDF/texto não são retidos, **não há corpus para vetorização** — RAG documental fica fora do escopo (seção 16).

### IA e privacidade de dados — seção 3
- A IA **nunca calcula dinheiro**; ela interpreta, classifica e redige sobre números já calculados pelo domínio.
- Logs de IA (`ai_usage_log`) guardam apenas **metadados de uso** (modelo, tokens, custo, latência, tipo) — **sem conteúdo sensível**.
- Embeddings: nenhum no MVP; se usados pós-MVP, só rótulos não sensíveis de categoria.

### Disponibilidade e degradação — seção 3.6
- Failover nativo da SDK (array de provedores).
- Em indisponibilidade total: mensagem ao usuário ("instabilidade, tentando novamente"), re-enfileirar e **degradar para parsing por comandos**.
- **Nunca persistir sem confirmação.**

### Exclusão e auditoria (LGPD) — seções 2 e 17
- Exclusão de dados é **lógica (soft delete)** conforme LGPD.
- O **histórico de auditoria é preservado** mesmo após exclusão lógica, sem expor dados sensíveis.
