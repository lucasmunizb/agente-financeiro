# 03 · Regras Financeiras Consolidadas

> Fonte de verdade: **Seção 4** do escopo final (`gestao_contas_ia_ESCOPO_FINAL`).
> Estas regras são **determinísticas** e vivem no **motor financeiro** (domínio), **100% cobertas por testes**. São a fonte da verdade dos cálculos. A IA nunca as executa.

---

## 4.1 · Parcelamento

Toda compra parcelada guarda: **valor total**, **valor por parcela**, **dia efetivo da parcela** e **parcela atual/total**.

- **Na primeira parcela (1/N):** gerar automaticamente **todas as N parcelas futuras**.
- **Ao importar uma parcela ≥ 2/N:** verificar se já existem as parcelas; se não existirem, **registrar todas** (inclusive as já passadas).
- **Otimização de varredura:** se na última extração de um cartão **nenhuma** parcela precisou ser adicionada, a próxima extração desse cartão **não revalida parcelas 3/X em diante** — apenas as `2/X`. Evita reprocessamento pesado.
- **A parcela atual NUNCA é salva como dado fixo** da fatura — ela é **sempre calculada na exibição**.

---

## 4.2 · Vencimentos

| Situação | Regra de vencimento |
|----------|---------------------|
| **Compra vinculada a cartão** | Respeita **SEMPRE o vencimento do cartão**. Registra a data da compra, mas o vencimento de cálculo/exibição é o do cartão. |
| **Compra fora de cartão** (PIX, débito, dinheiro, boleto) | Vencimento = **data da parcela / data da compra**. |
| **Cadastro manual parcelado em cartão** | Aceitar **somente se for a 1ª parcela**. Demais linhas: `+1 mês` cada e `+1` na parcela atual. |

---

## 4.3 · Pagamentos

- **Pagamento parcial:** registra normalmente e marca a cobrança como **parcialmente paga** (status próprio).
- **Pagamento antecipado — antes do fechamento do cartão:** abate o valor em aberto atual e **libera valor no gasto mensal**.
- **Pagamento antecipado — após o fechamento:** vale para o **próximo mês**; registra como pagamento parcial com o dia do vencimento do cartão.
- **Estornos, reembolsos e cancelamentos:** sempre registrados e separados por **FK** para a tabela `status_pagamento`.

---

## 4.4 · Status de pagamento

Conjunto inicial modelado como **tabela de referência** (`status_pagamento`) com FK nos lançamentos:

| Status | Significado |
|--------|-------------|
| `aberto` | Lançamento/conta em aberto, ainda não pago. |
| `pago` | Quitado integralmente. |
| `pago_parcial` | Pago parcialmente; saldo remanescente. |
| `vencido` | Vencimento anterior à data atual e ainda não pago. |
| `cancelado` | Cancelado pelo usuário (mantém histórico). |
| `estornado` | Estorno/reembolso registrado. |
| `pendente_revisao` | Extraído de PDF, aguardando confirmação humana — **nunca entra em cálculo até confirmar**. |
| `agendado` | Parcela futura ainda não vencida. |

---

## 4.5 · Cálculo do "disponível do mês" (fórmula oficial)

> **Disponível do mês = Receitas recebidas no mês − (Gastos em cartão com vencimento no mês) − (Gastos fora de cartão do mês: PIX e débito).**

O componente de cartão é atribuído pelo **mês de vencimento** de cada gasto (calculado em §4.2), **independente de a fatura já ter fechado**:

- **Cartão ainda em aberto (não fechado):** se o vencimento da fatura aberta cai no **mês corrente**, os gastos **já entram** no cálculo — é a visão antecipada de "quanto ainda tem para gastar" antes mesmo do fechamento.
- **Gastos que caem na fatura de um mês futuro** (ex.: compras feitas após o fechamento) **não entram** no mês corrente; entram no **mês do seu vencimento** e aparecem como **"gastos previstos do próximo mês"** (previsão/alerta). Cada gasto pertence a **um único** mês de vencimento — nunca é contado duas vezes.
- **No dia do vencimento**, registra-se o **pagamento do boleto** da fatura fechada.

Ressalvas:

- **Reseta mensalmente** (sempre mensal).
- **Duas visões:** o sistema mostra o **disponível do mês corrente** e os **gastos previstos para o próximo mês** (somatório de todas as cobranças com vencimento no mês seguinte).
- Considera **receita** recebida no mês (salário, PIX recebido, outros recebimentos).
- **Reserva financeira:** **não entra** no cálculo.
- **Transferências entre contas:** **não contam** como gasto.

---

## 4.6 · Contas, cartões e formas de pagamento

- **Cartões** identificados sempre pelos **4 dígitos finais + descrição** (ex.: "cartão pai"). Limite é **opcional**.
- Toda cobrança tem vínculo com uma **forma de pagamento** (`credito`, `debito`, `pix`, `dinheiro`, `boleto`). **Crédito** é a única em cartão (usa o vencimento do cartão); as demais são **fora de cartão** (vencem na data da compra/parcela). **Dinheiro não exige conta**; PIX e débito informam a conta (banco + descrição).
- **Datas separadas:** data da compra (sempre), data de vencimento (só cartão), data de pagamento (só ao confirmar pagamento).
- **Recorrências/assinaturas:** tabela específica com status `ativo`/`cancelado`.
- **Categoria única:** uma despesa **não** é dividida entre categorias.
- **Moeda estrangeira:** **fora do MVP**, mas o **modelo já deve estar pronto** (campo de moeda) para o pós-MVP.

---

> **Lembrete:** tudo nesta seção é **determinístico e 100% testado** — é o **motor financeiro**, núcleo do sistema. A IA apenas **aciona** e **formata** esses cálculos; nunca os produz.
