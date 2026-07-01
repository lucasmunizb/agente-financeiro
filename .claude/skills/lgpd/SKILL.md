---
name: lgpd
description: >-
  Use sempre que uma tarefa tocar dados pessoais neste projeto sob a ótica de conformidade
  com a LGPD — bases legais, finalidade, minimização, consentimento (`aceite_lgpd_em`),
  transparência sobre o uso de IA, direitos do titular (acesso, correção, exclusão,
  portabilidade), retenção/expurgo, anonimização, soft delete com auditoria preservada e
  resposta a incidente de dados. Dispare quando o usuário mencionar "LGPD", "dados
  pessoais", "dados sensíveis", "titular", "consentimento", "aceite_lgpd_em", "base legal",
  "finalidade", "minimização", "retenção", "expurgo", "direito de exclusão", "esquecimento",
  "portabilidade", "anonimização", "privacidade", "DPO"/"encarregado", "ANPD" ou "incidente
  de dados" — mesmo sem pedir explicitamente. É a camada de conformidade que atravessa
  backend, frontend e devops: aponta para a skill `frontend` (tela de consentimento),
  `seguranca-ia` (defesa adversarial), `governanca-ia` (determinismo) e `devops` (segredos)
  em vez de duplicá-las. NÃO use para construir a tela de consentimento em si (isso é
  `frontend`) nem para cálculo financeiro (`laravel-backend`).
---

# lgpd — conformidade e proteção de dados (camada que atravessa o produto)

Esta skill é o **especialista de conformidade LGPD** do projeto. Ela não constrói telas nem
calcula dinheiro: ela decide **o que pode ser tratado, com que base legal, por quanto tempo
e como o titular exerce seus direitos** — e traduz isso em invariantes verificáveis por
teste para as outras camadas.

> Fonte de verdade: **seção 10** do Escopo Final, consolidada em
> [`docs/09-nfr-seguranca-lgpd.md`](../../../docs/09-nfr-seguranca-lgpd.md). Em dúvida de
> regra, o escopo prevalece — **não invente exigência legal**: cite a seção ou pergunte.

## Como esta skill se relaciona com as outras

A LGPD é transversal. Esta skill **define o requisito**; a implementação vive na skill dona
da camada. Aponte para lá, não duplique:

| Assunto | Dono da implementação | O que a `lgpd` define |
|---|---|---|
| Tela/onboarding de consentimento, nada sensível no cliente | `frontend` | *quando* o consentimento é obrigatório e o que registrar (`aceite_lgpd_em`) |
| Extração de instruções, exfiltração entre usuários, injeção via PDF | `seguranca-ia` | *que dado* jamais pode vazar |
| IA nunca inventa/calcula dinheiro | `governanca-ia` | *que dado* pode ir ao provedor (só não sensível) |
| Docker Secrets, TLS, retenção de backup | `devops` | *que* precisa ser cifrado/segredo e por quê |
| Migrations, soft delete, jobs de expurgo, models | `laravel-backend` + `dba-postgres` | *o comportamento* de retenção/exclusão a implementar e testar |

---

## Os 5 princípios que governam cada decisão

Toda dúvida de "posso guardar/enviar/reter isto?" se resolve com estes princípios (LGPD
art. 6º), na ordem:

1. **Finalidade** — só trate o dado para a finalidade declarada (gestão financeira pessoal
   do próprio usuário). Sem finalidade nova sem nova base.
2. **Minimização (necessidade)** — persista o **mínimo**. Se o produto funciona sem o dado,
   **não** colete. É por isso que dados sensíveis da fatura (nome, endereço, CPF,
   nascimento) são **ignorados integralmente** — não são necessários para o lançamento.
3. **Transparência** — o titular sabe que há IA no fluxo e para quê seus dados servem.
4. **Segurança** — TLS em trânsito, segredos fora de imagem/log/versionamento, isolamento
   por usuário em toda operação.
5. **Efemeridade do sensível** — o que é sensível e desnecessário **não vive**: PDF e texto
   extraído têm **zero retenção**.

> Regra prática de bolso: **"na dúvida, não persista; se persistir, minimize; se for
> sensível e desnecessário, descarte."**

---

## Mapa de dados (o que é o quê)

Classifique todo dado novo antes de tocá-lo. Detalhe completo (inventário tipo ROPA) em
[`references/mapa-de-dados.md`](references/mapa-de-dados.md).

| Classe | Exemplos | Regra |
|---|---|---|
| **Pessoal — persistível** | e-mail, senha (hash), `telegram_user_id`, telefone verificado, lançamentos financeiros não sensíveis | Persiste com base legal e minimização; isolado por `user_id` |
| **Pessoal sensível — NUNCA persiste** | nome na fatura, endereço, CPF, data de nascimento presentes no PDF | **Ignorado integralmente**; nunca vira coluna nem log |
| **Efêmero — zero retenção** | PDF original, texto extraído (OCR/parse) | Processado em memória e **descartado ao final**, sempre |
| **Metadado — sem conteúdo** | `ai_usage_log` (modelo, tokens, custo, latência), `audit_log` | Guarda **uso**, nunca o conteúdo sensível |
| **Conversa — retenção curta** | `agent_conversations` / mensagens | **60 dias** com job de expurgo |

Se um dado não se encaixa em nenhuma linha, **pare e pergunte** antes de persistir.

---

## Bases legais e finalidade

- **Base do MVP:** **consentimento** do titular (art. 7º, I) para o tratamento de dados
  financeiros com finalidade de gestão pessoal, registrado em `users.aceite_lgpd_em`.
- **Finalidade única e declarada:** organizar as contas pessoais **do próprio usuário**.
  Nenhum uso secundário (marketing, venda, cruzamento entre usuários) — o isolamento por
  `user_id` é também um controle de finalidade.
- **Transparência sobre IA (obrigatória):** o usuário precisa saber, antes de usar, que
  mensagens são interpretadas por IA e que **a IA nunca decide valores** (isso vem do
  `governanca-ia`). O texto de transparência é conteúdo de `frontend`/bot; o **requisito**
  é desta skill.

Sem `aceite_lgpd_em` preenchido, o tratamento não tem base — o produto não deve operar para
aquele usuário. Trate isso como invariante de teste (ver abaixo).

---

## Direitos do titular (art. 18) — como atendemos

Todo direito abaixo precisa de um caminho **determinístico e auditável**. Procedimento
detalhado de atendimento em [`references/direitos-do-titular.md`](references/direitos-do-titular.md).

| Direito | Como o produto atende |
|---|---|
| **Acesso / confirmação** | Exportar os dados estruturados do próprio `user_id` |
| **Correção** | Edição de lançamentos (sempre com **confirmação antes de persistir**) |
| **Exclusão / esquecimento** | **Soft delete lógico**; auditoria preservada sem expor sensível |
| **Portabilidade** | Exportação em formato estruturado (só dados do próprio usuário) |
| **Informação sobre uso de IA** | Transparência no onboarding + resposta do bot |

**Exclusão é lógica, não física.** A LGPD convive com a obrigação de manter trilha de
auditoria: apaga-se/anonimiza-se o dado pessoal, **preserva-se o registro de auditoria** de
que houve tratamento — sem reexpor o que foi apagado. Nunca faça `DELETE` físico que destrua
a trilha; nunca deixe a trilha vazar o dado excluído.

---

## Retenção e expurgo

| Dado | Retenção | Mecanismo |
|---|---|---|
| PDF original + texto extraído | **Zero** | Descartado ao fim do processamento (efêmero) |
| Dados sensíveis da fatura | **Zero** | Nunca persistidos |
| Conversas com o agente | **60 dias** | Job de expurgo agendado (`schedule:work`) |
| Lançamentos financeiros | Enquanto durar a conta | Soft delete na exclusão |
| Auditoria | Preservada | Sobrevive à exclusão lógica, sem conteúdo sensível |

Consequência de projeto: como PDF/texto **não** são retidos, **não existe corpus** para
vetorização — **RAG documental está fora do escopo** (não crie infra vetorial no MVP).

---

## Incidente de dados (LGPD art. 48)

Se houver suspeita de vazamento/exposição indevida:

1. **Conter** — cortar o acesso (revogar segredo/rotacionar chave — acione `devops`).
2. **Registrar** — o quê, quando, quais titulares e categorias de dado, sem duplicar o dado
   exposto no registro do incidente.
3. **Avaliar risco** ao titular; comunicar ANPD/titulares **é decisão do usuário/encarregado**
   — traga os fatos, **não** presuma nem redija comunicação externa sem ordem explícita.
4. **Corrigir a causa** com teste que reproduz a falha primeiro (TDD), depois o fix.

Nunca cole em log, issue, commit ou mensagem o dado sensível envolvido no incidente.

---

## Test-first para conformidade (obrigatório)

LGPD aqui não é documento: é **invariante testável**. Como toda feature deste repositório,
**os testes vêm primeiro e devem falhar** antes da implementação (regra inviolável 2).
Escreva testes que provem a conformidade, por exemplo:

- **Minimização:** processar um PDF cujo texto contém CPF/nome/endereço e asseverar que
  **nenhuma** dessas strings foi persistida em qualquer tabela nem em log.
- **Efemeridade:** após o job de importação, o arquivo/temp e o texto extraído **não existem**.
- **Zero conteúdo em log de IA:** `ai_usage_log` de uma interação real não contém o texto do
  usuário — só metadados.
- **Base legal:** operação de usuário **sem** `aceite_lgpd_em` é barrada.
- **Exclusão:** após exclusão lógica, o dado pessoal não é mais legível, **mas** o
  `audit_log` do tratamento permanece e **não** reexpõe o dado.
- **Isolamento:** usuário A jamais lê dado de usuário B (também coberto por `seguranca-ia`).
- **Expurgo:** conversa com mais de 60 dias é removida pelo job.

Padrão: **especifique o cenário (Given-When-Then) → teste que falha → implemente → passa.**

---

## Reforço das regras invioláveis (valem sempre)

- **PDF e texto extraído: zero retenção.** Processamento efêmero, descartado ao final.
- **A IA nunca calcula dinheiro** e só recebe **dados não sensíveis** (ver `governanca-ia`).
- **Confirmação antes de persistir** todo registro/edição no MVP.
- **Dinheiro em centavos (BIGINT)**, fuso base America/Sao_Paulo, pt-BR só na borda.
- **Segredos** só via `.env` (dev) / **Docker Secrets** (prod) — nunca em imagem/log/git.
- **Tudo em contêiner** (`make ...`), nada instalado no host.
- **Frontend é etapa separada** — a tela de consentimento não sobe junto do backend.
- **Test-first (TDD)** — testes que falham antes de qualquer implementação.
- **NUNCA `git push`** — commits locais apenas; remoto só sob ordem explícita.

---

## Referências

- [`docs/09-nfr-seguranca-lgpd.md`](../../../docs/09-nfr-seguranca-lgpd.md) — NFRs, segurança e LGPD (fonte de verdade).
- [`docs/02-governanca-ia.md`](../../../docs/02-governanca-ia.md) — determinismo (IA nunca calcula/inventa).
- [`references/mapa-de-dados.md`](references/mapa-de-dados.md) — inventário de dados (ROPA) e classificação.
- [`references/direitos-do-titular.md`](references/direitos-do-titular.md) — procedimento de atendimento a cada direito.
- Skills irmãs: `frontend` (consentimento na tela), `seguranca-ia` (defesa adversarial), `devops` (segredos), `laravel-backend`+`dba-postgres` (soft delete, expurgo, migrations).
