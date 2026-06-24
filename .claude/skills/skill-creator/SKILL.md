---
name: skill-creator
description: Use sempre que for criar, revisar ou refatorar uma skill deste projeto (qualquer arquivo em .claude/skills/), ou quando o usuário mencionar "criar skill", "nova skill", "skill-creator", "autorar skill" ou pedir para padronizar/melhorar uma skill existente — mesmo sem pedir explicitamente. Ensina a anatomia, a divulgação progressiva, a escrever a description-gatilho e a reforçar as regras invioláveis do projeto.
---

# skill-creator — autorar skills com qualidade

Esta é a skill **meta**: use-a para escrever todas as próximas skills do projeto com o
mesmo padrão. O objetivo de uma skill é entregar, sob demanda, o contexto e o
procedimento certos para uma tarefa — sem inflar o contexto quando ela não é necessária.

## Quando disparar
- Vai criar uma skill nova (ex.: `governanca-ia`, `telegram`, `importacao-pdf`, frontend).
- Vai revisar/refatorar uma skill existente.
- O usuário pede um "guia", "procedimento" ou "padrão" reutilizável para um assunto.

## Anatomia de uma skill

```
.claude/skills/<skill-name>/
  SKILL.md            # obrigatório: frontmatter (name + description) + corpo
  references/         # opcional: material carregado sob demanda (links no SKILL.md)
  scripts/            # opcional: scripts utilitários executáveis
  assets/             # opcional: templates, exemplos, imagens
```

- O frontmatter **exige** `name` e `description`. `name` em kebab-case, igual ao nome da
  pasta.
- Mantenha o `SKILL.md` **abaixo de ~500 linhas**. Se passar disso, quebre o excedente em
  `references/<tema>.md` e deixe um ponteiro claro no corpo (ex.: "Para o parser do Itaú,
  ver `references/itau.md`").

## Divulgação progressiva (3 níveis)

1. **Sempre em contexto (~100 palavras):** apenas `name` + `description`. É o que decide se
   a skill dispara. Mantenha enxuto.
2. **Ao disparar:** o corpo do `SKILL.md` entra em contexto. Aqui vão o procedimento e os
   exemplos. Mire < 500 linhas.
3. **Sob demanda:** arquivos em `references/` só são lidos quando o corpo aponta para eles.
   Use para detalhes longos, tabelas grandes, exemplos extensos.

## A `description` é o gatilho — escreva-a com cuidado

Descreva **o que a skill faz E quando usá-la**, citando contextos e frases reais do
usuário. Seja levemente **insistente** para evitar subdisparo.

- ✅ "Use sempre que o usuário mencionar X, Y ou Z, mesmo sem pedir explicitamente."
- ❌ "Skill para X." (vago, dispara mal)

Inclua sinônimos e gatilhos prováveis. Uma boa `description` é a diferença entre a skill
ser útil e ser ignorada.

## Estilo de escrita

- **Voz imperativa.** "Escreva o teste primeiro", não "deve-se escrever".
- **Explique o porquê** em vez de encher de "MUST/NUNCA" sem contexto. Uma razão curta
  convence e generaliza melhor do que uma ordem seca.
- **Exemplos no formato Input/Output.** Mostre entrada realista e a saída esperada.
- **Mantenha geral.** Não prenda a skill a um único exemplo; ele ilustra, não define.
- **Princípio da não-surpresa.** A skill faz exatamente o que a `description` diz. Nada de
  efeitos colaterais inesperados, nada de conteúdo malicioso.

## Reforço obrigatório das regras do projeto

Toda skill criada aqui **deve reforçar** (no corpo, onde fizer sentido) as regras
invioláveis — porque elas valem para qualquer trabalho neste repositório:

- **Test-first (TDD)** — testes que falham antes da implementação.
- **Frontend é etapa separada** — nunca junto do backend.
- **IA via Laravel AI SDK** (`laravel/ai`) — sem cliente HTTP próprio; guard determinístico
  por cima (a IA nunca calcula dinheiro).
- **Tudo em contêiner** — nada no host além de `make`.
- **NUNCA `git push`** — commits locais apenas; remoto só com ordem explícita.

Ver `CLAUDE.md` para a lista completa.

## Validação leve (faça antes de considerar pronta)

Depois de escrever a skill, gere **2–3 prompts realistas** que um usuário diria e confira:

1. A `description` dispararia a skill nesses prompts? (Se não, ajuste a `description`.)
2. O corpo responde de fato à tarefa, com procedimento e exemplo?
3. Algo que deveria estar em `references/` está inflando o corpo?

Exemplo de teste para uma futura skill `telegram`:
- Input: "como faço o vínculo do usuário no bot?" → deve disparar e explicar token +
  `request_contact` + `telegram_user_id` (sem MAC).
- Input: "o bot recebeu a mesma mensagem duas vezes" → deve apontar dedupe por `update_id`.

## Fluxo recomendado para criar uma skill

1. Defina o **escopo de disparo** (frases do usuário) e escreva a `description`.
2. Esboce o **procedimento** (passos imperativos) e 1–2 exemplos Input/Output.
3. Adicione o reforço das regras invioláveis pertinentes.
4. Extraia detalhes longos para `references/`.
5. Rode a validação leve (2–3 prompts) e ajuste.
6. Commit **local** (nunca push).
