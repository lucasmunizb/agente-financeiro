# Mapa de dados (inventário tipo ROPA) — projeto Gestão de Contas com IA

Inventário de referência do tratamento de dados pessoais. Serve para responder rápido
"posso persistir/enviar isto?" e para embasar o atendimento a direitos do titular. Alinhado
a [`docs/09-nfr-seguranca-lgpd.md`](../../../../docs/09-nfr-seguranca-lgpd.md) e ao modelo de
dados ([`docs/04-modelo-dados.md`](../../../../docs/04-modelo-dados.md)).

## Legenda de classe

- **P** — dado pessoal persistível (mínimo necessário, isolado por `user_id`).
- **S** — pessoal **sensível/desnecessário**: NUNCA persiste, NUNCA vai à IA.
- **E** — efêmero: existe só durante o processamento, **zero retenção**.
- **M** — metadado: guarda uso, **sem conteúdo sensível**.

## Inventário

| Dado | Classe | Onde vive | Finalidade | Base legal | Retenção | Vai à IA? |
|---|---|---|---|---|---|---|
| E-mail | P | `users` | Autenticação/identificação | Consentimento | Vida da conta | Não |
| Senha (hash) | P | `users` | Autenticação | Consentimento | Vida da conta | Não |
| `aceite_lgpd_em` | P | `users` | Prova de consentimento | Consentimento | Vida da conta | Não |
| `telegram_user_id` | P | `users` | Vínculo do bot | Consentimento | Vida da conta | Não |
| Telefone verificado | P | `users` | Vínculo/notificação | Consentimento | Vida da conta | Não |
| Lançamentos financeiros (valor, data, categoria, origem) | P | `transactions`/afins | Gestão financeira | Consentimento | Vida da conta (soft delete) | Só campos não sensíveis, quando necessário |
| Nome/endereço/CPF/nascimento na fatura | **S** | — | — | — | **Zero** | **Nunca** |
| PDF original da fatura | **E** | memória/tmp | Importação | Consentimento | **Zero** | Nunca (só o resultado estruturado não sensível) |
| Texto extraído (OCR/parse) | **E** | memória | Importação | Consentimento | **Zero** | Só trechos não sensíveis, se necessário |
| Conversa com o agente | P (curta) | `agent_conversations`/mensagens | Contexto do diálogo | Consentimento | **60 dias** (expurgo) | Sim (histórico não sensível) |
| Log de uso de IA | M | `ai_usage_log` | Custo/observabilidade | Legítimo interesse operacional | Definida p/ métrica | — |
| Auditoria | M | `audit_log` | Trilha de tratamento/LGPD | Cumprimento de obrigação | Preservada | Não |

## Regras de decisão rápidas

- **Sensível da fatura (S):** se o parser encontrar nome/endereço/CPF/nascimento, **ignore**
  — não crie coluna, não logue, não mande à IA. Teste isso (busca das strings em todas as
  tabelas e logs → deve dar vazio).
- **Efêmero (E):** ao fim do job, PDF/temp e texto extraído **não podem existir**. Teste a
  ausência do arquivo e da variável persistida.
- **Metadado (M):** `ai_usage_log` guarda modelo/tokens/custo/latência/tipo — **nunca** o
  texto do usuário. Se precisar de amostra de conteúdo para debug, isso é decisão explícita
  do usuário e não entra no MVP.
- **Conversa:** retém 60 dias; o job de expurgo é obrigatório e testado.
- **Qualquer dado novo não listado:** pare e classifique antes de persistir. Na dúvida, não
  persista.
