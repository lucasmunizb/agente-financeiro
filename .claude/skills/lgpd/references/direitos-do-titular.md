# Atendimento aos direitos do titular (LGPD art. 18) — procedimento

Cada direito tem um caminho **determinístico, isolado por `user_id` e auditável**. Toda
implementação segue TDD (teste que falha primeiro) e nunca faz `git push` (regra inviolável).
Confirme com o usuário nos pontos de decisão; não presuma prazos legais nem redija
comunicação externa sem ordem explícita.

## Princípios comuns a todos os pedidos

- **Escopo por usuário:** toda consulta/ação é filtrada pelo `user_id` autenticado. Um
  titular só acessa/altera/exclui **os próprios** dados. (Reforça `seguranca-ia`.)
- **Auditável:** todo atendimento gera registro em `audit_log` (quem, quando, qual direito),
  sem reexpor conteúdo sensível.
- **Confirmação antes de persistir** qualquer alteração/exclusão (regra inviolável 7).

## 1. Acesso / confirmação de tratamento

- Retorne os **dados estruturados** vinculados ao `user_id`: cadastro, lançamentos,
  vínculos, e a informação de que há tratamento por IA (transparência).
- **Não** inclua dados que o produto não retém (PDF/texto extraído/sensível de fatura) —
  eles não existem.
- Formato legível/estruturado (o mesmo da portabilidade serve).

**Teste:** export de A contém só dados de A; nunca vaza dado de B; não contém campos
sensíveis que não deveriam existir.

## 2. Correção

- Reutilize o fluxo de **edição de lançamentos** já existente, sempre com **confirmação
  antes de persistir**.
- Registre a correção na auditoria (valor antes/depois pode ser sensível? aqui é financeiro
  não sensível — ok; ainda assim, minimize).

**Teste:** edição exige confirmação; grava auditoria; isolada por usuário.

## 3. Exclusão / direito ao esquecimento

- **Soft delete lógico**, nunca `DELETE` físico que destrua a trilha.
- Após excluir: o dado pessoal fica **ilegível/anonimizado**; o `audit_log` **preserva** o
  fato do tratamento e da exclusão, **sem reexpor** o dado apagado.
- Conversas seguem seu próprio expurgo (60 dias) além da exclusão sob demanda.

**Teste (crítico):**
- Given um usuário com lançamentos e histórico
- When ele pede exclusão
- Then os dados pessoais não são mais legíveis **e** o `audit_log` do tratamento permanece
  **e** o `audit_log` **não** contém o dado excluído em claro.

## 4. Portabilidade

- Exportação em **formato estruturado** (ex.: JSON/CSV) apenas dos dados do próprio usuário.
- Mesma barreira de escopo por `user_id` do acesso.

**Teste:** export estruturado só com dados do titular; parseável; sem dados de terceiros.

## 5. Informação sobre uso de IA (transparência)

- O titular deve poder saber **que** há IA no fluxo e **que a IA não decide valores**
  (determinismo — ver `governanca-ia`).
- Texto/estado de UI é responsabilidade de `frontend`/bot; o **requisito de existir** é da
  skill `lgpd`.

**Teste:** onboarding não permite prosseguir sem consentimento; o consentimento registra
`aceite_lgpd_em`.

## Anti-padrões (não faça)

- ❌ `DELETE` físico que apaga a trilha de auditoria.
- ❌ Auditoria que reexpõe o dado que o titular pediu para excluir.
- ❌ Export que vaza dado de outro `user_id`.
- ❌ Atender pedido sem registrar em auditoria.
- ❌ Presumir prazo legal / notificar ANPD por conta própria — traga os fatos ao usuário.
