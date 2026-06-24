# 10 · Estratégia de testes (TDD) e separação backend/frontend

> Fonte de verdade: **seção 11** do Escopo Final (com apoio das seções 0, 2, 3 e 13).
> Princípio inegociável: **nada é implementado antes dos testes**, e **frontend nunca é construído junto com a feature de backend**.

## Regra de processo (vale para todo o roadmap)

Estas duas regras governam **todas** as fases do roadmap (seções 13 e 14) e o TODO (seção 18):

1. **Nada é implementado antes dos testes.** Para cada feature: escrever testes unitários (e de contrato quando aplicável) que **FALHAM**, depois implementar até passarem, garantindo cobertura.
2. **Frontend é sempre tarefa separada e posterior.** Mensagens formatadas do bot e telas do webapp **NUNCA** são construídos junto com a feature de backend. Ordem fixa: **backend** (domínio + testes + API/handlers) primeiro; **frontend** depois, como tarefa separada.

## Ordem por feature (seção 11.1)

Para cada feature, sempre nesta sequência:

1. **Especificar o comportamento** e os critérios de aceite em **Gherkin / Given-When-Then**.
2. **Escrever os testes unitários do domínio** (motor financeiro: parcelas, disponível, duplicidade...) — **devem falhar**.
3. **Implementar o domínio** até os testes passarem.
4. **Escrever os testes de contrato/integração** da API/handler — **devem falhar**.
5. **Implementar a borda** (endpoint/handler) até passarem.
6. **Somente em etapa separada e posterior:** construir a apresentação (resposta do bot / tela web).

```
Gherkin (Given-When-Then)
        │
        ▼
Testes unitários de domínio (FALHAM)
        │
        ▼
Implementar domínio  ──►  suite verde
        │
        ▼
Testes de contrato/integração (FALHAM)
        │
        ▼
Implementar borda (endpoint/handler)  ──►  suite verde
        │
        ▼
[ETAPA SEPARADA] Apresentação (bot/web)
```

## Cobertura prioritária (seção 11.2)

Estas regras determinísticas vivem no motor financeiro e precisam de cobertura completa antes de qualquer implementação dependente:

1. **Cálculo de parcelas** e geração de parcelas futuras (regras da seção 4.1).
2. **Cálculo de vencimentos**: cartão vs. fora de cartão (seção 4.2).
3. **Fórmula do disponível do mês** (seção 4.5).
4. **Detecção de duplicidade** na importação (seção 8.2).
5. **Resolução de datas relativas** no fuso de **São Paulo** (`America/Sao_Paulo` — seção 3.4).
6. **Normalização monetária**: tudo em **centavos inteiros** (`BIGINT`), sem ponto flutuante.
7. **Guard pós-geração da IA**: **nenhum número fora do payload** calculado (seção 3.3).

## Ferramentas e testes determinísticos da IA

- **Pest / PHPUnit** como frameworks de teste do Laravel 12.
- A IA deve ser testada de forma **determinística** usando os **fakes nativos da Laravel AI SDK** (`laravel/ai`): fake de agentes, de structured output e de embeddings (seção 3.7). Isso permite escrever os testes que falham **antes** de qualquer chamada real a provedor.
- O **guard determinístico permanece sendo nosso**: a SDK fornece tools e structured output, mas não garante que um número veio do banco. Os tools financeiros devolvem valores já calculados pelo domínio, e a resposta da IA é validada contra esse payload — esse comportamento entra na cobertura prioritária (item 7).

## Lembrete de processo

Cada fase do roadmap **começa pelos testes (TDD)** e entrega **somente o backend**. As entregas de frontend (FE) acontecem depois, por feature, **nunca acopladas** à feature de backend. Um item de backend só é considerado concluído com **testes passando e cobertura garantida**.
