# 08 · Categorias e classificação

> Referência: seção 9 do Escopo Final.

A classificação automática começa **determinística (lookup)**, **não por IA pesada**. Isso responde "como identificar categoria" sem depender de RAG e mantém o comportamento previsível e testável.

---

## 1. Classificação determinística (lookup)

- **`categories` + `category_keywords`** — tabela de categorias com tabela de palavras-chave para o lookup. Exemplos:
  - `hotel` / `passagem` / `airbnb` → **viagem**;
  - `bar` / `jogo` / `futebol` → **lazer**;
  - nomes de loja → **compras**.
- **`merchant_aliases`** — regras fixas por estabelecimento e apelidos. Exemplo: "**sempre Uber = transporte**".

## 2. Aprendizado por correção

- A **correção do usuário vira ou atualiza** um alias (`merchant_aliases`) ou uma palavra-chave (`category_keywords`).
- O sistema melhora de forma incremental e **determinística**, sem treino de modelo.

## 3. Regras das categorias

- **Categoria única por despesa** — uma despesa não é dividida entre categorias.
- **Sem subcategoria no MVP.**
- Categorias têm **cor e ícone**.
- Categorias podem ser **arquivadas sem perder o histórico**.

## 4. Similaridade semântica (opcional, pós-MVP)

- Similaridade semântica via **embeddings (`pgvector`)** é **opcional e só pós-MVP**, como **reforço** do lookup — nunca obrigatória.
- Se usada, opera apenas sobre **rótulos não sensíveis** de categoria (ver seção 16/17).

---

## 5. Categorias iniciais sugeridas

- Alimentação
- Apostas
- Futebol
- Moradia
- Transporte
- Saúde
- Lazer
- Educação
- Assinaturas
- Cartão/Taxas
- Outros

O usuário pode criar **categorias personalizadas**.

---

## 6. Fora do escopo do MVP

- **Orçamento por categoria** é **pós-MVP** (no MVP há orçamento mensal geral + alerta por categoria).
- Subcategorias e metas: pós-MVP.
