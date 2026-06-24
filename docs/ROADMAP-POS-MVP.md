# Roadmap Pós-MVP

> Fonte de verdade: seções 15 e 16 do escopo final.

A arquitetura já nasce preparada para multiusuário, canais desacoplados e moeda estrangeira, então as evoluções abaixo entram **sem reescrita** — apenas ativando o que já está modelado.

## Ordem recomendada

| Ordem | Evolução | Observação |
| --- | --- | --- |
| 1 | **OCR avançado** | Primeira alteração pós-MVP (faturas escaneadas/imagens). |
| 2 | **WhatsApp** | Após OCR; reutiliza o domínio (canal desacoplado). |
| 3 | **Comandos por áudio** | Após WhatsApp. |
| 4 | **Relatórios comparativos por mês/ano + exportação** | Após áudio. |
| 5 | **Orçamento por categoria, metas, subcategorias** | Refinamento financeiro. |
| 6 | **Multiusuário/família e permissões** | Arquitetura já preparada. |
| 7 | **Moeda estrangeira / IOF / encargos** | Modelo já preparado para isso (campo de moeda existente). |
| 8 | **Recomendações financeiras / anomalias** | **Apenas alertas; nunca aconselhamento** (no MVP só existem alertas operacionais). |
| 9 | **App mobile nativo (+ RAG documental se necessário)** | Última etapa, só se houver necessidade comprovada. |

## Veredito sobre RAG

**RAG documental NÃO entra no MVP — e provavelmente nunca**, dado o escopo atual.

O motivo é simples: a decisão de **NUNCA reter PDFs nem o texto extraído elimina o corpus** sobre o qual o RAG operaria. Sem documentos guardados, não há o que recuperar.

| Necessidade levantada | Veredito |
| --- | --- |
| Perguntas financeiras (gastos, saldo, vencimentos) | **SQL determinístico.** Nunca RAG. |
| Perguntas sobre faturas antigas / documentos | Sem corpus (PDF descartado). **RAG não se aplica.** |
| Classificação de categoria | **Lookup determinístico** (palavras-chave + aliases). Embeddings (pgvector) só como reforço opcional pós-MVP. |
| Histórico de conversa | **Tabela de mensagens (60 dias).** Não exige RAG. |

**Conclusão:** o único uso vetorial potencialmente justificável é a similaridade semântica de estabelecimento→categoria, e mesmo esse começa determinístico. RAG completo sobre documentos antigos fica como última etapa hipotética, apenas se a política de retenção mudar e houver necessidade real comprovada.
