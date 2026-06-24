# Glossário

> _Ver seção 19 do escopo (termos adicionais derivados das seções 2, 3 e 7)._

| Termo | Explicação |
|---|---|
| **Motor financeiro** | Serviço de domínio determinístico e testado que calcula **todos** os valores monetários (parcelas, vencimentos, disponível do mês). É quem calcula dinheiro — nunca a IA. |
| **Tool use** | Mecanismo em que a IA só pode chamar **ferramentas pré-definidas e parametrizadas** (ex.: `consultar_gastos`), sempre filtradas pelo usuário. Sem SQL livre e sem escrita direta pela IA. |
| **Guard pós-geração** | Validação que, antes de enviar a resposta, extrai todo número/data do texto da IA e confere que **cada um existe no payload calculado**. Divergência bloqueia e regenera/usa fallback. |
| **Pré-importação** | Estado temporário dos dados extraídos de uma fatura, **antes** da confirmação humana. Nunca entra em cálculo até ser aceito (status `pendente_revisao`). |
| **Fonte de verdade** | O **banco relacional** (PostgreSQL), onde vivem os dados financeiros confiáveis e auditáveis. |
| **Soft delete** | Exclusão **lógica** que preserva histórico/auditoria conforme a LGPD. O registro é marcado como excluído, não removido fisicamente. |
| **Disponível do mês** | Receitas recebidas no mês − cartões com vencimento no mês − gastos fora de cartão do mês (PIX/débito). Reseta mensalmente; reserva financeira e transferências não entram. |
| **RAG** | _Retrieval-Augmented Generation_ — recuperação de documentos para apoiar respostas da IA. **Fora do escopo** deste produto: sem PDF retido, não há corpus a recuperar. |
| **pgvector** | Extensão do PostgreSQL para busca por similaridade (embeddings). Uso **opcional e pós-MVP**, apenas como reforço da categorização. |
| **Idempotência** | Garantia de que reprocessar a mesma operação (ex.: a mesma mensagem do Telegram) **não gera duplicidade**. No MVP é assegurada por unique constraint no banco. |
| **Laravel AI SDK (`laravel/ai`)** | Pacote first-party do Laravel 12 que implementa **toda** a camada de IA: Agents, tools, structured output, memória de conversa (`RemembersConversations`), failover entre provedores e fakes para teste. Sem cliente HTTP próprio. |
| **Docker Secrets** | Mecanismo do Docker Swarm que monta segredos (chaves de DB, IA, Telegram, `APP_KEY`) em `/run/secrets/<nome>`. Usado **em produção, sem `.env`**. |
| **Dedupe por `update_id`** | Estratégia que descarta mensagens duplicadas do Telegram causadas por instabilidade, mantendo a **primeira**, usando o `update_id` único como chave de idempotência. |
