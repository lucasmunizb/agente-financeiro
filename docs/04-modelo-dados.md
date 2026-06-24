# 04 · Modelo de dados

> Fonte de verdade: **seção 5** do escopo final. Modelagem relacional de referência (PostgreSQL 16).

## Princípios transversais (valem para TODAS as entidades)

- **Dinheiro em BIGINT (centavos).** Todo valor monetário é inteiro em centavos. Elimina erro de ponto flutuante e mantém o cálculo determinístico; a formatação pt-BR acontece apenas na borda (exibição).
- **Todo registro tem `user_id`.** Isolamento por usuário e preparo para multiusuário futuro, mesmo que o MVP seja de uso individual.
- **Soft delete + auditoria em tabelas sensíveis.** Exclusão é lógica (LGPD), preservando o histórico de auditoria.
- **Parcela atual é calculada, nunca fixada.** O número da parcela vigente é sempre derivado na exibição, jamais persistido como dado fixo da fatura (regra da seção 4.1).
- **Origem auditável.** Lançamentos guardam a origem (`manual` / `telegram` / `pdf`).

---

## Entidades

### users
| Campos-chave | Notas |
|---|---|
| `id`, `email`, `senha_hash`, `timezone`, `aceite_lgpd_em` | Autenticação por e-mail/senha no MVP. |

### telegram_links
| Campos-chave | Notas |
|---|---|
| `user_id`, `telegram_user_id`, `telefone`, `token`, `status`, `vinculado_em` | Vínculo único por conta (apenas um ativo). Vínculo seguro via `telegram_user_id` + telefone verificado — ver seção 7. |

### categories
| Campos-chave | Notas |
|---|---|
| `id`, `user_id`, `nome`, `cor`, `icone`, `arquivada` | Categoria única por gasto. Sem subcategoria no MVP. Categorias podem ser arquivadas sem perder histórico. |

### category_keywords
| Campos-chave | Notas |
|---|---|
| `categoria_id`, `palavra_chave` | Lookup determinístico de categorização (ex.: "hotel/passagem" → viagem). |

### merchant_aliases
| Campos-chave | Notas |
|---|---|
| `user_id`, `alias`, `categoria_id` | Apelidos de estabelecimento + regra fixa ("Uber = transporte"). Correção do usuário vira/atualiza um alias. |

### cards
| Campos-chave | Notas |
|---|---|
| `id`, `user_id`, `descricao`, `final_4`, `limite?`, `dia_fechamento`, `dia_vencimento` | Cartão identificado pelos 4 dígitos finais + descrição. Limite é opcional. |

### accounts
| Campos-chave | Notas |
|---|---|
| `id`, `user_id`, `banco`, `descricao` | Conta bancária para PIX/débito. |

### payment_methods
| Campos-chave | Notas |
|---|---|
| `id`, `tipo` (credito/debito/pix/dinheiro) | Tabela de referência. Toda cobrança tem vínculo com uma forma de pagamento. |

### status_pagamento
| Campos-chave | Notas |
|---|---|
| `id`, `codigo`, `descricao` | Tabela de referência (ver seção 4.4). Conjunto inicial: `aberto`, `pago`, `pago_parcial`, `vencido`, `cancelado`, `estornado`, `pendente_revisao`, `agendado`. |

### transactions
| Campos-chave | Notas |
|---|---|
| `id`, `user_id`, `descricao`, `valor_total_cents`, `data_compra`, `payment_method_id`, `card_id?`, `account_id?`, `categoria_id?`, `status_id`, `origem` (manual/telegram/pdf), `moeda` | Lançamento. `origem` auditável. Campo `moeda` preparado para moeda estrangeira pós-MVP (assume BRL no MVP). |

### installments
| Campos-chave | Notas |
|---|---|
| `id`, `transaction_id`, `numero`, `total`, `valor_cents`, `vencimento`, `status_id` | Parcelas. **A parcela atual é calculada, não fixada** — a coluna registra a estrutura N/total, mas a "parcela vigente" é derivada na exibição. |

### recurrences
| Campos-chave | Notas |
|---|---|
| `id`, `user_id`, `descricao`, `valor_cents`, `periodicidade`, `dia`, `status` (ativo/cancelado) | Assinaturas/recorrentes. |

### incomes
| Campos-chave | Notas |
|---|---|
| `id`, `user_id`, `descricao`, `valor_cents`, `data`, `tipo` (fixa/variavel), `recorrencia?` | Base do cálculo do "disponível do mês". |

### invoices
| Campos-chave | Notas |
|---|---|
| `id`, `user_id`, `card_id`, `competencia`, `vencimento` | Agrupa os lançamentos de um cartão por competência. |

### invoice_imports
| Campos-chave | Notas |
|---|---|
| `id`, `user_id`, `card_id`, `hash_arquivo_nome`, `status`, `criado_em` | Pré-importação. **O PDF não é salvo**; apenas metadados de controle (incl. hash do nome do arquivo para deduplicação). |

### budgets
| Campos-chave | Notas |
|---|---|
| `id`, `user_id`, `mes`, `limite_cents`, `categoria_id?` | Orçamento geral no MVP; por categoria (alerta) fica para o pós-MVP. |

### audit_log
| Campos-chave | Notas |
|---|---|
| `id`, `user_id`, `entidade`, `acao`, `antes`, `depois`, `origem`, `criado_em` | Auditoria **sem dados sensíveis**. Histórico de criação/edição/importação/exclusão. |

### ai_usage_log
| Campos-chave | Notas |
|---|---|
| `id`, `user_id`, `tipo`, `modelo`, `tokens_in`, `tokens_out`, `custo_cents`, `latencia_ms` | Custo de IA por chamada. Metadados de uso, sem conteúdo sensível. |

### messages (SDK)
| Campos-chave | Notas |
|---|---|
| Tabelas `agent_conversations` / `agent_conversation_messages` (via trait `RemembersConversations`) | Conversa gerida pela Laravel AI SDK. **Retenção de 60 dias** com job de expurgo. Usado para contexto, completar mensagens incompletas e auditoria. |

### pdf_parse_errors
| Campos-chave | Notas |
|---|---|
| `id`, `banco_id`, `descricao_erro`, `criado_em` | Tabela de bancos + relação N:N de erros, para evoluir o parser. |

---

## Atenção de consistência

A decisão de **nunca reter o PDF nem o texto extraído** (seção 2 / seção 8) torna **inviável vetorizar o conteúdo da fatura**:

- Embeddings de fatura saem do escopo — não há corpus para indexar.
- O que persiste são apenas os **lançamentos** (dados **não sensíveis**); nome, endereço, CPF e data de nascimento são ignorados integralmente.
- Por consequência, isso **esvazia a necessidade de RAG documental** (ver veredito da seção 16): sem documentos guardados, não há o que recuperar.
