# Spec NN — <Título da etapa>

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra inviolável 2), **backend antes do
> frontend** (regra 3). Em qualquer dúvida de regra, o **escopo final** e os
> `docs/` de referência **prevalecem** sobre suposições — não invente regra financeira.
>
> Um spec é "vivo": ao concluir, marque o status, preencha **§10 Estado atual** com os
> artefatos reais (arquivos, comandos) e registre as decisões de regra que você tomou.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Bloco N · FN |
| **Status** | ⬜ Planejado · 🟡 Em andamento · ✅ Concluído |
| **Depende de** | [[spec-NN]] … |
| **Habilita** | [[spec-NN]] … |
| **Fonte de verdade** | seção X do escopo · [`docs/NN-...`](../NN-....md) |
| **Regras críticas** | nº das regras invioláveis que incidem (ex.: 2, 4, 5, 7) |

---

## 1. Objetivo
Uma frase com o valor entregue ao usuário/sistema.

## 2. Escopo
- **Inclui (backend desta etapa):** …
- **Não inclui (outro spec / frontend / pós-MVP):** …

## 3. Cenários de aceite (Given-When-Then)
Os comportamentos verificáveis. São a base dos testes de §7.

- **C1 —** **Dado** … **Quando** … **Então** …
- **C2 —** **Dado** … **Quando** … **Então** …
- **C3 (borda/erro) —** **Dado** … **Quando** … **Então** …

## 4. Barreiras e invariantes
Regras invioláveis e barreiras anti-alucinação que esta etapa **precisa** garantir
(ex.: dinheiro só em centavos; IA nunca calcula; escopo estrito por `user_id`;
determinismo de "agora"; nada sensível persistido; confirmar antes de gravar).

## 5. Modelo de dados
Tabelas/colunas criadas ou tocadas — ou **"nenhuma"**. Tipos, FKs, índices e
constraints relevantes (par com a skill `dba-postgres`).

## 6. Contratos do domínio
Classes/serviços/VO/enums principais com a **assinatura pública** e o papel de cada um.
A IA (quando houver) entra só para interpretar/redigir; o cálculo é determinístico.

## 7. Plano de testes (test-first — devem falhar primeiro)
1. **Unitários do domínio** — … (lista objetiva)
2. **Contrato/integração** (borda: handler/API/comando/agent fake) — …

> Cada item de backend só é "feito" com **testes verdes e cobertura**. Use os **fakes da
> Laravel AI SDK** para a camada de IA (offline, determinístico).

## 8. Backend agora · Frontend depois
| Backend (esta etapa) | Frontend (etapa separada e posterior) |
|---|---|
| … | … |

## 9. Definition of Done
- [ ] Cenários de §3 cobertos por testes que falhavam antes e agora passam.
- [ ] Barreiras de §4 garantidas (com teste para cada uma que for crítica).
- [ ] Sem segredo/PDF/dado sensível persistido ou commitado.
- [ ] Commit local atômico, em português, separando backend de frontend.
- [ ] §10 preenchida com os artefatos reais.

## 10. Estado atual / artefatos
- **Status:** ⬜/🟡/✅
- **Entregue:** arquivos, migrations, comandos, configs (caminhos reais).
- **Adiado para:** [[spec-NN]] / frontend / pós-MVP.
- **Decisões de regra tomadas:** …
