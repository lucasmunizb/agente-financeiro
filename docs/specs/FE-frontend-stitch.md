# Spec FE — Frontend (Stitch): todas as telas do sistema

> **Como usar este spec.** Diferente das demais specs (backend, test-first), esta é a
> **fase de apresentação** (regra inviolável 3): consolida **todo o frontend** adiado das
> etapas 00–07. O fluxo aqui é **design-first com o Stitch**: parte-se do **design system**
> (§4), gera-se **uma tela por prompt** (§7) e só depois vem a **ligação técnica** ao
> Laravel (Inertia/Blade) e a redação das **mensagens do bot** (§8), em código.
>
> Spec "vivo": ao gerar/aprovar cada tela, marque o item no **mini-TODO (§6)** e registre
> em **§10** o artefato real (arquivo exportado / componente).

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Fase **FE** (frontend consolidado) |
| **Status** | ⬜ Planejado |
| **Depende de** | [[spec-02-cadastro-manual-receitas]] · [[spec-03-telegram]] · [[spec-04-ia-interpretacao]] · [[spec-05-chat-financeiro]] · [[spec-06-dashboard]] |
| **Habilita** | MVP apresentável (web + bot) |
| **Ordem** | Após o backend das features (00–06); **antes** da importação de PDF ([[spec-07-importacao-pdf]]). As telas da 07 ficam aqui marcadas para gerar **após** o backend dela. |
| **Fonte de verdade** | [`docs/05-arquitetura.md`](../05-arquitetura.md) · [`docs/06-telegram.md`](../06-telegram.md) · [`docs/08-categorias.md`](../08-categorias.md) · [`docs/09-nfr-seguranca-lgpd.md`](../09-nfr-seguranca-lgpd.md) |
| **Regras críticas** | 3 (frontend separado) · 4 (a IA nunca calcula — UI **nunca** recalcula dinheiro no cliente) · 5 (centavos no backend; pt-BR só na borda) · 7 (confirmar antes de gravar) · 6 (nada sensível persistido) |

---

## 1. Objetivo
Dar ao usuário uma leitura **calma, clara e confiável** das suas contas — na web e no bot —
gerando todas as telas com o **Stitch** a partir de um **design system único**, sem que a
camada visual recalcule dinheiro nem invente número (todo valor vem do backend, regra 4).

## 2. Escopo
- **Inclui:**
  - O **design system** (§4): paleta, tipografia, espaçamento, componentes, voz e a tela-assinatura.
  - **Um prompt de Stitch por tela** (§7), pronto para colar, derivado do design system.
  - O **mini-TODO** (§6) com todas as telas a gerar e seu estado.
  - As **mensagens do bot** (§8) — texto curto, sem botões salvo confirmação — como *copy* de referência.
- **Não inclui (etapas posteriores / outras specs):**
  - **Ligação técnica** das telas geradas ao Laravel (rotas, Inertia/Blade, chamadas à API,
    validação server-side). É tarefa de implementação **após** aprovar o desenho de cada tela.
  - Qualquer **regra de negócio ou cálculo** (já vive no backend — specs 01–06).
  - Telas de **importação de PDF** só são **geradas após** o backend da [[spec-07-importacao-pdf]]
    (constam no mini-TODO marcadas).

## 3. Princípios e invariantes de apresentação
Valem para **toda** tela e todo prompt (são as "barreiras" desta fase):

1. **Dinheiro é leitura, nunca cálculo no cliente.** A UI **exibe** valores já calculados
   (centavos → pt-BR na borda) e **captura** entradas; **nunca** soma, parcela ou projeta
   no front (regra 4/5). Totais, parcelas e "disponível" chegam prontos do backend.
2. **Confirmar antes de gravar (regra 7).** Todo registro/edição/cancelamento passa por uma
   confirmação explícita com **prévia** do que será gravado. Auto-save é proibido no MVP.
3. **Transparência de IA (barreira 5).** Toda resposta gerada por IA exibe a **fonte**
   (período, filtros, nº de registros consultados) e o selo **"número conferido"**; um aviso
   discreto lembra que a IA **não inventa valores**. Em degradação, mostra-se o fallback
   **sem números** com um aviso de instabilidade.
4. **Escopo estrito por usuário.** Nenhuma tela mostra dado de terceiros; "não encontrado"
   nunca revela existência de dados de outra conta.
5. **Nada sensível em tela persistida (regra 6).** PDF/texto extraído é efêmero; a revisão
   de importação trabalha sobre dados em memória da sessão, descartados ao final.
6. **pt-BR e fuso America/São Paulo na borda.** `R$ 1.234,56`, `10 de junho`, `3x`,
   datas e percentuais sempre formatados na exibição.
7. **Piso de qualidade.** Responsivo até **360px**, **foco de teclado visível**, contraste
   **WCAG AA**, alvos de toque ≥ 44px, `prefers-reduced-motion` respeitado.

## 4. Design System (a base de todos os prompts)
> Direção deliberada — **calma, clareza e confiança**, fugindo dos clichês de fintech (azul
> corporativo) e dos defaults de IA (cream+serif / preto+verde-ácido / jornal). Mobile-first.

### 4.1 Conceito
**"Caderno de contas"** — a precisão de um extrato, com a calma de um caderno. O dinheiro é
tratado como **dado tabular** (monoespaçado, alinhado por coluna, como num extrato bancário),
o resto da interface é arejado e humano.

### 4.2 Paleta (modo dia)
| Token | Hex | Uso |
|---|---|---|
| `tinta` | `#1C1B17` | texto principal (quase-preto **quente**) |
| `papel` | `#EDF0E8` | fundo da aplicação (névoa pálida verde-acinzentada) |
| `superficie` | `#FBFBF8` | cartões e campos |
| `cedula` | `#1F6E5A` | primária — saldo positivo, ações, marca (verde-cédula) |
| `cedula-clara`| `#2E8B72` | hover/ativo da primária |
| `ocre` | `#C9852A` | atenção / **a vencer** |
| `argila` | `#B4452F` | negativo / **em atraso** (uso parco) |
| `linha` | `#DDE0D7` | hairlines, bordas, divisores |
| `nevoa` | `#6B6F66` | texto secundário, labels |

**Modo noite (opcional):** `tinta`↔`papel` invertem para `#14150F` (fundo) / `#ECEEE6`
(texto); `cedula` clareia para `#3FA589`. Manter contraste AA.

### 4.3 Tipografia
- **Display (títulos):** *Bricolage Grotesque* — característica, usada com moderação.
- **Corpo (UI/texto):** *IBM Plex Sans*.
- **Dados/dinheiro (mono):** *IBM Plex Mono* — **assinatura tipográfica**: **todo** valor em
  R$, data, %, contagem de parcelas é **monoespaçado e alinhado por coluna**, como num extrato.
- Escala (rem): 2.5 / 2 / 1.5 / 1.25 / 1 / 0.875 / 0.75. Sentence case sempre.

### 4.4 Forma, espaço e movimento
- **Raio:** 12px (cartões), 8px (inputs/botões), pill (chips/selos).
- **Espaço:** base 4px; respiros generosos; densidade só nas listas/extratos.
- **Sombra:** difusa e discreta (calma); nunca dura.
- **Motion:** comedido — entrada dos cards `fade+rise` ~150ms, micro-hover; respeitar
  `prefers-reduced-motion`. Excesso de animação **não**.

### 4.5 Tela-assinatura: **"A régua do mês"**
Uma **régua horizontal do mês** (dia 1 → último dia) com: marcador do **hoje**, **ticks** de
vencimentos (em `ocre`), e uma faixa sutil do **disponível** decrescendo ao longo do mês.
Vive no **Dashboard** e ecoa como uma **fina espinha de progresso** no topo das telas do mês.
É o elemento memorável — tudo ao redor permanece quieto.

### 4.6 Componentes recorrentes
Card de resumo · chip de **categoria** (cor + ícone) · selo de **status** (aberto/pago/atraso/
cancelado) · chip de **fonte** da IA · linha de **lançamento** (descrição · categoria · valor
mono · data · status) · barra de **orçamento** (consumo/limite) · **prévia de parcelas**
(tabela mono) · banner de **transparência de IA** · estados **vazio/carregando/erro** com texto-guia.

### 4.7 Voz (copy)
pt-BR, sentence case, verbos diretos, sem jargão técnico. Exemplos canônicos:
"Disponível do mês", "A vencer", "Confirmar gasto", "Conferido". Erros **diretos e úteis**
(o que houve + como resolver), na voz da interface, sem pedir desculpas vagas.

## 5. Como gerar no Stitch
1. **Crie um projeto** no Stitch e cole **uma vez** o **Prompt-base de tema** (§7.0) para fixar
   paleta, tipografia e tom — ele orienta todas as telas seguintes.
2. Para cada tela do mini-TODO, cole o **prompt da tela** (§7.x). Cada prompt é autocontido e
   **reafirma o tema** (caso o Stitch perca contexto entre gerações).
3. **Itere** com ajustes curtos ("aumente o respiro", "alinhe os valores à direita em mono").
4. **Exporte** o design/código e registre em §10. A **ligação ao Laravel** (Inertia/Blade,
   dados reais, validação) é a etapa técnica seguinte — fora do Stitch.

> **Shell padrão (aside + header + coluna de chat) — já criado.** Todas as telas **logadas**
> compartilham o mesmo **shell**. No desktop ele tem **três zonas**: (1) uma **barra lateral
> (aside)** de navegação à esquerda, (2) o **conteúdo da tela** no centro e (3) uma **coluna de
> chat fixa, sempre aberta**, à direita (o Chat financeiro, §7.14). Há ainda o **cabeçalho
> (header)** no topo. Aside e header já foram **gerados no Stitch** e viram um **layout Blade**
> reutilizado; a coluna de chat entra como a **terceira zona** do mesmo shell. Por isso, os
> prompts das telas logadas (§7.5–§7.17) pedem **apenas o conteúdo da área principal** — o Stitch
> **não** deve redesenhar aside, header nem a coluna de chat nelas. **No mobile** (não cabem três
> colunas) a coluna de chat **recolhe** para um lançador e abre como folha (regra do layout, ver
> §7.14). As telas **pré-login** (§7.1–§7.4) não têm shell (sem navegação do app).

## 6. Mini-TODO — telas a gerar
> Marque conforme gerar/aprovar. Telas de importação dependem do backend da [[spec-07-importacao-pdf]].

**Tema**
- [x] 0. Prompt-base de tema aplicado no projeto Stitch

**A. Entrada & onboarding**
- [x] 1. Login
- [x] 2. Criar conta
- [x] 3. Onboarding + consentimento LGPD
- [x] 4. Vínculo do Telegram

**B. Núcleo financeiro**
- [x] 5. Dashboard / Visão geral *(tela-assinatura: a régua do mês; shell aside + header — **implementado em Blade e ligado ao backend (spec-06)**: valores reais do domínio, estado vazio/pronto pelos dados, coberto por testes de feature)*
- [ ] 6. Lançamentos — lista
- [ ] 7. Lançamento — criar/editar (com prévia de parcelas)
- [x] 7b. Registrar gasto — **modal rápido** (FAB do dashboard) *(valor, forma, cartão, vencimento, pagamento opcional, recorrente, categoria)* — **gerado no Stitch**, **implementado em Blade** e **ligado ao backend** (persiste de verdade): fluxo em dois passos (formulário → prévia calculada pelo backend → confirmar → grava, regra 7), cartões/categorias reais do usuário, validação server-side (Form Request), reuso de `RegistrarGastoManual`. Coberto por testes de borda web. **Deferido:** marcar como pago (data de pagamento) e recorrência (backend pós-MVP).
- [ ] 8. Lançamento — detalhe (parcelas + status)
- [ ] 9. Confirmações pendentes *(espelho web do "Confirma?" — regra 7)*
- [ ] 10. Receitas
- [ ] 11. Orçamento do mês
- [ ] 12. Categorias
- [ ] 13. Cartões & faturas

**C. IA**
- [x] 14. Chat financeiro — **coluna fixa (3ª coluna do shell, sempre aberta)** *(histórico no topo · entrada de texto · anexo somente-PDF · fonte/trace + estados; NÃO é overlay — o body fica entre a nav e o chat; recolhe no mobile)* — **gerado no Stitch** ("Chat financeiro — coluna fixa") e **implementado em Blade** como a 3ª zona do `x-layouts.app`: componente `x-chat.panel` (cabeçalho, banner de transparência, histórico rolável, entrada com anexo somente-PDF). Sempre aberta a partir de `lg` (conteúdo com `lg:pr-[380px]`); abaixo de `lg` recolhe para folha, aberta pelo lançador do header. JS mínimo (folha, validação de PDF, campo que cresce). Estado vazio + estados de interação (pensando · instável · anexado · inválido) conduzidos pelo `chat.js`. **Ligado ao backend (real):** conversa pelo **mesmo motor do Telegram** (`ResponderConsulta` → `AssistenteDeConsulta` + guard barreira 4 + fontes barreira 5) via `POST /chat/mensagens`; histórico **real** e isolado por usuário em `chat_messages` (retenção de 60 dias no expurgo `ai:expurgar-conversas`), injetado pelo view composer. Anexo **PDF-only validado por MIME real** no servidor (`seguranca-ia`) e **efêmero** (nunca persistido — regra 6/`lgpd`); a extração de fatura ([[spec-07-importacao-pdf]]) segue pós-MVP (PDF válido recebe aviso honesto). **Deferido:** memória de conversa (contexto multi-turno) e registro de gasto por linguagem natural no chat.

**D. Importação de PDF — gerar após o backend da [[spec-07-importacao-pdf]]**
- [ ] 15. Importar fatura (upload)
- [ ] 16. Revisão da importação (lote + duplicados + confirmar)

**E. Conta**
- [ ] 17. Configurações & privacidade (perfil, fuso, vínculo, transparência de IA, exclusão LGPD)

**Mensagens do bot (texto, §8 — não-Stitch)**
- [ ] B1–B11 redigidas e implementadas em código (ver §8)

---

## 7. Prompts de Stitch (um por tela)

### 7.0 Prompt-base de tema (colar uma vez no projeto)
```text
Você é meu designer de UI. Vou gerar várias telas de um app de FINANÇAS PESSOAIS em
português do Brasil, mobile-first (responsivo até 360px) e também usável em desktop.
Sensação-alvo: CALMA, CLAREZA e CONFIANÇA — finanças sem ansiedade. NÃO use azul de fintech
nem visual genérico. Conceito: "caderno de contas" — a precisão de um extrato com a calma
de um caderno.

PALETA:
- Texto/quase-preto quente: #1C1B17
- Fundo (névoa pálida verde-acinzentada): #EDF0E8
- Cartões/superfícies: #FBFBF8
- Primária (verde-cédula): #1F6E5A  | hover: #2E8B72
- Atenção / "a vencer": #C9852A
- Negativo / "em atraso": #B4452F (uso parco)
- Linhas/bordas: #DDE0D7
- Texto secundário/labels: #6B6F66

TIPOGRAFIA:
- Títulos: "Bricolage Grotesque" (característica, com moderação)
- Texto de interface: "IBM Plex Sans"
- VALORES (R$), datas, %, parcelas: "IBM Plex Mono" — SEMPRE monoespaçado e alinhado à
  direita por coluna, como num extrato bancário. Esta é a assinatura visual.
- Tudo em sentence case.

FORMA: cantos 12px (cartões) / 8px (botões e campos) / pill (chips). Respiros generosos,
sombras difusas e suaves (nunca duras). Animação comedida.

REGRAS DE CONTEÚDO:
- Valores sempre em pt-BR: "R$ 1.234,56". Datas: "10 de junho". Parcelas: "3x".
- A interface NUNCA calcula dinheiro; apenas exibe valores prontos e captura entradas.
- Respostas de IA mostram a FONTE (período/filtros) e um selo "número conferido".
- Acessível: contraste AA, foco de teclado visível (anel verde-cédula), alvos ≥ 44px.

ESTADOS PADRÃO (valem para todas as telas): vazio (texto-guia que convida à ação, nunca
um "nada aqui" seco), carregando (esqueleto sóbrio ou rótulo no próprio botão) e erro
(mensagem direta — o que houve + como resolver — na voz da interface, sem desculpas vagas).
TELAS PRÉ-LOGIN (login, criar conta, onboarding, vínculo): sem barra de navegação do app;
um card calmo e centralizado, com bastante respiro.

Use conteúdo de exemplo realista pt-BR (mercado, transporte, fatura do cartão, aluguel).
Confirme que entendeu o tema e aguarde o pedido da primeira tela.
```

### 7.1 Login
**Objetivo:** entrar com calma e confiança. **Estados:** erro de credencial; carregando.
```text
Tela de LOGIN (use o tema). Mobile-first; no desktop, o card fica centralizado com bastante
respiro, sem hero ilustrado.

ESTRUTURA
- Topo enxuto: a marca em texto, "Caderno de contas", no verde-cédula, com um hairline
  (#DDE0D7) abaixo. Nada de logo genérico.
- Card central (superfície #FBFBF8, cantos 12px, sombra difusa):
  - Campo "E-mail".
  - Campo "Senha" com botão mostrar/ocultar.
  - Link discreto "Esqueci a senha" alinhado à direita.
  - Botão primário "Entrar" (verde-cédula, largura total, alvo ≥ 44px).
- Abaixo do card, divisor sutil e a linha "Novo por aqui? Criar conta" (link verde-cédula).
- Rodapé minúsculo (texto secundário): "Seus números vêm do seu banco de dados — a IA nunca os inventa."

ESTADOS (gere como variações)
- Erro de credencial: mensagem inline direta sob os campos — "E-mail ou senha incorretos."
- Carregando: botão com rótulo "Entrando…" e spinner sóbrio; campos desabilitados.

A calma vem do espaço e da tipografia, não de ilustração. Foco de teclado visível (anel verde-cédula).
```

### 7.2 Criar conta
**Objetivo:** cadastro mínimo. **Estados:** validação inline; carregando.
```text
Tela CRIAR CONTA (use o tema). Mobile-first, um campo por linha; mesmo card calmo do login.

ESTRUTURA
- Card: "Nome", "E-mail", "Senha" (com medidor de força simples em 3 níveis — "fraca / ok /
  forte"), "Confirmar senha".
- Checkbox "Li e aceito os termos e a política de privacidade", com os dois links sublinhados.
- Botão primário "Criar conta" (largura total). Abaixo: "Já tenho conta — entrar".

ESTADOS (variações)
- Validação inline: "Use um e-mail válido."; "As senhas não conferem."; consentimento
  obrigatório com aviso "Aceite os termos para continuar."
- Carregando: botão "Criando conta…".

Tom acolhedor e breve, sem upsell. Sentence case.
```

### 7.3 Onboarding + consentimento LGPD
**Objetivo:** explicar finalidades e obter consentimento (doc 09). **Estados:** —
```text
Tela ONBOARDING / CONSENTIMENTO (use o tema). Um único passo, honesto e curto — primeira
tela depois de criar a conta.

ESTRUTURA
- Título display "Antes de começar".
- Três blocos curtos, cada um com um ícone simples (de linha, não preenchido) e uma frase:
  1) "Acompanhe seus gastos" — registre na web ou direto no Telegram.
  2) "IA que interpreta, não inventa" — a IA classifica e redige; os números vêm do seu
     banco de dados, calculados pelo sistema.
  3) "Privacidade" — PDFs de fatura são processados e descartados na hora; conversas ficam
     por até 60 dias; nenhum dado sensível é guardado.
- Caixa de consentimento destacada (superfície, borda fina): checkbox "Concordo com o
  tratamento dos meus dados para as finalidades acima" + link "política de privacidade".
- Botão primário "Começar" (desabilitado até marcar o consentimento).
- Link discreto ao final: "Você pode excluir seus dados quando quiser."

Tom direto e tranquilo. Sem ilustração grande; os três ícones e o espaço bastam.
```

### 7.4 Vínculo do Telegram
**Objetivo:** parear a conta ao bot. **Estados:** pendente (token válido); expirado; **vinculado**.
```text
Tela VÍNCULO DO TELEGRAM (use o tema). Mobile-first. Aqui a ORDEM dos passos importa de
verdade, então numere os passos.

ESTRUTURA (estado PENDENTE, token válido)
- Título "Conectar o Telegram" + uma linha: "Registre e consulte gastos direto no chat."
- Card de passos numerados (1, 2):
  1) "Abra o bot" — botão primário "Abrir no Telegram" (deeplink t.me/seubot?start=TOKEN).
  2) "Confirme o telefone" — "O bot vai pedir para compartilhar seu contato. É assim que eu
     confirmo que o número é seu."
- TOKEN em destaque MONOESPAÇADO, grande e copiável (ícone copiar), com a etiqueta
  "código de uso único".
- Contador "expira em 14:32" em mono; vira ocre quando faltam menos de 2 minutos.
- Botão secundário "Gerar novo código".

ESTADOS (variações)
- VINCULADO: card em verde-cédula suave, selo "Conectado ✓", o @usuário em mono e botão
  secundário "Desconectar".
- EXPIRADO: token esmaecido, aviso direto "Este código expirou." e botão primário
  "Gerar novo código".

Deixe claro que o token só aparece nesta tela e serve uma única vez.
```

### 7.5 Dashboard / Visão geral — *tela-assinatura*
**Objetivo:** leitura instantânea do mês. **Estados:** vazio (primeiro mês); carregando.

> **Shell já criado.** A barra lateral (aside) e o cabeçalho (header) padrão do app já foram
> gerados no Stitch e serão o layout base reutilizado por todas as telas logadas — por isso
> este prompt (e os §7.6–§7.17) pede **apenas o conteúdo** da área principal.
```text
Tela DASHBOARD / VISÃO GERAL (use o tema). ESTA É A TELA-ASSINATURA. NÃO gere barra lateral
(aside) nem cabeçalho (header) — eles já são o SHELL PADRÃO do app. Gere APENAS o conteúdo da
área principal, que será encaixado dentro desse shell. Mobile-first, vira grade no desktop.

ELEMENTO-ASSINATURA — "A RÉGUA DO MÊS": uma régua horizontal do dia 1 ao último dia do mês,
com um marcador do "hoje", TICKS nas datas de vencimento (em ocre) e uma faixa sutil mostrando
o "disponível" diminuindo ao longo do mês. Posicione logo abaixo do cabeçalho. É o herói visual.

SELETOR DE MÊS ("Junho de 2026", com setas ‹ ›) acima ou junto da régua.

CARDS DE RESUMO (valores em mono, alinhados à direita):
- "Disponível do mês" — destaque, R$ 2.480,00 (verde-cédula se positivo).
- "Gastos do mês" — R$ 3.120,00, com mini-comparativo discreto vs. mês anterior.
- "A vencer (7 dias)" — R$ 540,00 em ocre, "3 contas".
- "Fatura do cartão" — "Nubank · fecha 28 de junho" R$ 1.870,00.

GRÁFICO: donut "gastos por categoria" com legenda (Mercado, Transporte, Restaurante, Moradia,
Lazer) — cada uma com sua cor/ícone de chip.

LISTA: "Próximas contas" (3 linhas: descrição · valor mono · "vence 30 de junho" · selo status).

Estado vazio: convite "Registre seu primeiro gasto" + dica de usar o Telegram. Nada pisca; a
régua é a única coisa que se move (animação sutil de entrada). A interface NUNCA calcula
dinheiro — todos os valores chegam prontos do backend.
```

### 7.6 Lançamentos — lista
**Objetivo:** ver/filtrar/agir sobre lançamentos. **Estados:** vazio; filtro sem resultado; carregando.
```text
Tela LANÇAMENTOS (use o tema). NÃO gere barra lateral (aside) nem cabeçalho (header) — eles
são o SHELL PADRÃO já definido no Dashboard (§7.5). Gere APENAS o conteúdo da área principal,
que será encaixado dentro desse shell (título da página desta tela: "Lançamentos").
Estilo EXTRATO: denso, legível, valores em mono à direita.
- Barra de filtros (chips): período (mês), categoria, cartão/forma, status (aberto/pago/atraso/
  cancelado). Campo de busca por descrição.
- Lista agrupada por dia. Cada linha: descrição · chip de categoria · valor (mono) · forma/
  cartão · selo de status. Em parcelado, mostrar "2/3" em mono.
- Toque na linha abre o detalhe; deslizar/menu com "Editar", "Cancelar", "Excluir".
- Rodapé fixo com total filtrado (mono) — rotulado "total exibido" (apenas leitura, vem pronto).
- Estado vazio e "nenhum lançamento neste filtro" com texto-guia para ajustar filtros.
```

### 7.7 Lançamento — criar/editar (com prévia de parcelas)
**Objetivo:** capturar um gasto e **confirmar** antes de gravar (regra 7). **Estados:** validação; crédito exige cartão; prévia de parcelas.
```text
Tela CRIAR/EDITAR LANÇAMENTO (use o tema). NÃO gere barra lateral (aside) nem cabeçalho
(header) — eles são o SHELL PADRÃO já definido no Dashboard (§7.5). Gere APENAS o conteúdo da
área principal, que será encaixado dentro desse shell (título da página: "Novo lançamento").
Formulário calmo, um campo por linha no mobile.
Campos: "Descrição"; "Valor" (campo mono, prefixo R$); "Data" (date picker, default hoje);
"Forma de pagamento" (crédito, débito, pix, dinheiro, boleto); "Cartão" (aparece e fica
OBRIGATÓRIO só quando a forma é crédito); "Parcelas" (1 a 24, só faz sentido no crédito);
"Categoria" (seletor com chips coloridos + ícone; sugestão automática destacada, editável).

PRÉVIA DE PARCELAS (aparece quando parcelas > 1): tabela monoespaçada com nº, valor da parcela
e data de vencimento de cada uma — rotulada "Prévia (ainda não gravado)". Deixe explícito que
os valores foram calculados pelo sistema, não pela tela.

Rodapé: botão primário "Revisar e confirmar" (NÃO grava direto — abre confirmação com o resumo).
Validação inline e direta ("Crédito exige um cartão."). Sem auto-save.
```

### 7.7b Registrar gasto — *modal rápido (FAB do dashboard)*
**Objetivo:** registrar um gasto **sem sair da tela atual**, por um **modal** disparado pelo
FAB "Registrar gasto" do Dashboard (§7.5, hoje "em breve"). É o **atalho de captura rápida**;
o formulário completo com edição e histórico permanece na página §7.7. Ambos gravam o **mesmo**
lançamento e obedecem às mesmas barreiras (regra 4: a tela não calcula; regra 7: confirmar
antes de gravar).

> **Campos e obrigatoriedade** derivam do domínio (doc 03 §4.6): forma de pagamento
> ∈ {crédito, débito, pix, dinheiro, boleto}; **crédito** é a única em cartão. **Datas
> separadas:** compra/vencimento/pagamento. **Vencimento é determinístico** — no crédito ele é
> **calculado pelo cartão** (nunca digitado); fora de cartão, é a própria data. **Recorrência**
> existe no modelo (assinaturas) mas o **backend é pós-MVP** — no design o switch já aparece;
> a ligação real fica pendente. **Visibilidade do switch (decisão de design):** só nas formas
> **à vista** (pix, débito, dinheiro, boleto); **oculto no crédito**, pois crédito usa parcelas
> e recorrência/parcelamento não se combinam.

> **Prompt fechado (anti-invenção).** O Stitch preenche buraco com invenção; a defesa é não
> deixar buraco: cada campo, rótulo e valor literal, uma lista explícita do que é **proibido**
> gerar, e os campos condicionais **separados por variação** (crédito × à vista). Cole do zero.

```text
Gere um COMPONENTE MODAL "Registrar gasto" de um app de finanças pessoais em pt-BR. Ignore
qualquer versão anterior desta tela — comece do zero. Gere DUAS TELAS (variações) deste mesmo
modal (detalhadas no fim). É só este modal: NÃO gere nenhuma outra tela, fluxo ou página.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo (backdrop atrás do modal): #EDF0E8
- Superfície do card e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, nº de parcela: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px no card, 8px em campos/botões, pill nos chips. Sombra difusa e suave.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere barra lateral (aside), cabeçalho (header), menu, abas, breadcrumb, logo, avatar,
  ilustração, imagem, banner, gráfico, nem rodapé de página. Só o modal sobre o backdrop.
- NÃO adicione, remova, renomeie nem reordene NENHUM campo além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas, tooltips, ícones decorativos, contadores,
  "termos", social login, nem qualquer conteúdo que não esteja escrito aqui entre aspas.
- Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA DO MODAL (igual nas duas variações) ━━━
- Backdrop escurecido cobrindo a tela; o modal é um card central #FBFBF8, cantos 12px, sombra
  difusa; largura confortável no desktop, quase full-width no mobile (funciona a 360px).
- Topo: título "Registrar gasto" (Bricolage Grotesque) à esquerda e botão "X" (fechar) à direita.
- Uma nota discreta abaixo do título: "* obrigatório".
- Campos empilhados, UM POR LINHA, cada um com seu rótulo acima. Obrigatórios com "*".
- Rodapé fixo do card, com uma linha fina (#DDE0D7) acima: botão primário "Revisar e confirmar"
  (verde-cédula, à direita) e botão secundário "Cancelar" (contorno verde-cédula, à esquerda).

━━━ CAMPOS FIXOS (aparecem nas DUAS variações, nesta ordem) ━━━
1. "Descrição" * — input de texto, valor: "Mercado do mês".
2. "Valor" * — input MONO com prefixo "R$" e o número alinhado à direita, valor: "R$ 450,00".
3. "Forma de pagamento" * — controle segmentado com EXATAMENTE 5 opções, nesta ordem:
   "Crédito", "Débito", "Pix", "Dinheiro", "Boleto". (Se não couber numa linha, quebre em duas
   linhas de segmentos de largura igual — NÃO junte nem remova opções.) O segmento selecionado
   fica em verde-cédula #1F6E5A com texto claro; os demais neutros.
… (aqui entram os campos condicionais, que MUDAM por variação — ver abaixo) …
ÚLTIMO campo (sempre por último, nas duas variações):
"Categoria" * — seletor de chips (pill) com ícone + rótulo, nesta ordem: "Mercado",
   "Restaurante", "Transporte", "Moradia", "Lazer", "Outros". O chip "Mercado" está
   SELECIONADO (fundo verde-cédula) e traz um selo pequeno "sugerido". Os demais neutros.

━━━ VARIAÇÃO A — forma selecionada: "Crédito" ━━━
Segmento "Crédito" selecionado. Entre o campo 3 e a Categoria, mostre NESTA ordem:
- "Cartão" * — dropdown MONO, valor: "Nubank •••• 1234 — fecha dia 28".
- "Parcelas" — seletor numérico, valor: "3". Logo abaixo, uma TABELA MONO rotulada
  "Prévia — calculada pelo sistema (ainda não gravado)", com 3 linhas (colunas: nº · valor ·
  vencimento), alinhadas à direita:
      "1/3"  "R$ 150,00"  "vence 05/07"
      "2/3"  "R$ 150,00"  "vence 05/08"
      "3/3"  "R$ 150,00"  "vence 05/09"
- "Data de vencimento" * — NÃO é um campo editável: mostre um CHIP somente-leitura, texto:
  "vence 5 de julho · calculado pelo cartão".
- "Data de pagamento" — rótulo "Data de pagamento (opcional)", campo de data VAZIO, com apoio
  discreto: "vazio = em aberto".
NESTA variação NÃO existe o switch de recorrência (crédito usa parcelas). Não o desenhe aqui.

━━━ VARIAÇÃO B — forma selecionada: "Pix / Dinheiro" (à vista) ━━━
Segmento "Pix" selecionado (o comportamento de "Dinheiro" é idêntico). Entre o campo 3 e a
Categoria, mostre NESTA ordem — e NÃO mostre "Cartão", "Parcelas" nem a tabela de prévia:
- "Data de vencimento" * — campo de DATA EDITÁVEL, valor: "05/07/2026".
- "Data de pagamento" — rótulo "Data de pagamento (opcional)", campo de data VAZIO, com apoio
  discreto: "vazio = em aberto".
- "Gasto recorrente" — um SWITCH rotulado "Repete todo mês?", mostrado LIGADO, com apoio
  discreto: "assinaturas e contas fixas". Por estar ligado, revela abaixo dois campos:
      "Periodicidade" — dropdown, valor: "mensal".
      "Dia" — seletor de dia do mês, valor: "5".

━━━ INVARIANTES ━━━
A interface NUNCA calcula dinheiro nem vencimento: o valor por parcela, a prévia e o vencimento
do cartão chegam prontos do backend — a tela só exibe. Acessível: contraste AA, foco de teclado
visível (anel verde-cédula), alvos de toque ≥ 44px, funciona a partir de 360px.

Entregue as DUAS variações como telas separadas: "Registrar gasto — Crédito" e
"Registrar gasto — Pix/Dinheiro".
```

> **Estados adicionais (gerar depois, como variações à parte — não no prompt principal):**
> validação inline ("Informe uma descrição."; "Informe um valor."; "Escolha a forma de
> pagamento."; "Crédito exige um cartão."; "Escolha uma categoria."); **crédito sem cartão
> cadastrado** (aviso + link "cadastrar um cartão", "Revisar e confirmar" desabilitado);
> **salvando** (botão "Salvando…", campos desabilitados). Ficam fora do prompt fechado acima
> para não poluir as duas variações-base; gere um de cada vez, com o mesmo rigor literal.

### 7.8 Lançamento — detalhe (parcelas + status)
**Objetivo:** ver um lançamento e suas parcelas. **Estados:** com parcela paga (edição bloqueada).
```text
Tela DETALHE DO LANÇAMENTO (use o tema). NÃO gere barra lateral (aside) nem cabeçalho (header)
do app — eles são o SHELL PADRÃO já definido no Dashboard (§7.5). Gere APENAS o conteúdo da
área principal, que será encaixado dentro desse shell.
- Cabeçalho do conteúdo (não confundir com o header do app): descrição, chip de categoria,
  valor total (mono) e selo de status.
- Metadados: forma/cartão, data, origem ("manual" ou "Telegram" ou "fatura PDF").
- TABELA DE PARCELAS (mono): nº, valor, vencimento, status de cada uma (aberto/pago/atraso).
- Ações: "Editar" e "Cancelar". Se houver parcela paga, "Editar" fica desabilitado com a
  explicação "Há parcelas pagas — não é possível editar; você pode cancelar as futuras."
- Tudo é leitura de dados prontos; a tela não recalcula nada.
```

### 7.9 Confirmações pendentes — *espelho web do "Confirma?"*
**Objetivo:** materializar a regra 7 na web (gastos interpretados aguardando "sim"). **Estados:** vazio.
```text
Tela CONFIRMAÇÕES PENDENTES (use o tema). NÃO gere barra lateral (aside) nem cabeçalho
(header) — eles são o SHELL PADRÃO já definido no Dashboard (§7.5). Gere APENAS o conteúdo da
área principal, que será encaixado dentro desse shell (título da página: "Confirmações
pendentes"). Lista de itens interpretados (ex.: vindos do Telegram) aguardando confirmação
antes de gravar.
- Cada item é um card com a PRÉVIA: descrição, valor (mono), categoria sugerida, forma/cartão,
  prévia de parcelas se houver, e a frase "Pronto para gravar — confirme".
- Dois botões por card: "Confirmar" (primário) e "Ajustar" (abre o formulário 7.7).
- Se a IA pediu esclarecimento, mostrar a pergunta ("Qual cartão? Nubank ou Itaú?") com opções.
- Estado vazio tranquilo: "Nada para confirmar agora."
Deixe claro que NADA foi gravado até o "Confirmar".
```

### 7.10 Receitas
**Objetivo:** cadastrar/listar receitas (base do disponível). **Estados:** vazio.
```text
Tela RECEITAS (use o tema). NÃO gere barra lateral (aside) nem cabeçalho (header) — eles são
o SHELL PADRÃO já definido no Dashboard (§7.5). Gere APENAS o conteúdo da área principal, que
será encaixado dentro desse shell (título da página: "Receitas").
- Resumo do mês: "Receitas de junho" R$ 6.500,00 (mono).
- Filtro por tipo: fixa / variável.
- Lista: descrição · tipo · valor (mono) · data/competência.
- Botão "Adicionar receita" abre formulário simples (descrição, valor, tipo, data) com
  "Revisar e confirmar". Sem cálculo no cliente.
```

### 7.11 Orçamento do mês
**Objetivo:** ver limite e consumo (total + por categoria). **Estados:** sem orçamento definido; estouro.
```text
Tela ORÇAMENTO DO MÊS (use o tema). NÃO gere barra lateral (aside) nem cabeçalho (header) —
eles são o SHELL PADRÃO já definido no Dashboard (§7.5). Gere APENAS o conteúdo da área
principal, que será encaixado dentro desse shell (título da página: "Orçamento do mês").
- Card topo: "Limite do mês" R$ 4.000,00 e "Consumido" R$ 3.120,00 (mono), com uma barra de
  progresso calma; ao passar de 100%, a barra usa argila e mostra "acima do limite".
- Lista por categoria: chip da categoria, barra consumo/limite, valores em mono. Categorias
  sem limite aparecem como "sem limite", mostrando só o consumo.
- Texto-guia se não houver orçamento: "Defina um limite para acompanhar o consumo."
- Apenas leitura dos números (vêm prontos); a tela só visualiza.
```

### 7.12 Categorias
**Objetivo:** gerenciar categorias (cor, ícone, palavras-chave, arquivar). **Estados:** —
```text
Tela CATEGORIAS (use o tema). NÃO gere barra lateral (aside) nem cabeçalho (header) — eles são
o SHELL PADRÃO já definido no Dashboard (§7.5). Gere APENAS o conteúdo da área principal, que
será encaixado dentro desse shell (título da página: "Categorias").
- Grade/lista de categorias, cada uma com seu chip (cor + ícone) e contagem de uso.
- Editar: nome, cor (paleta restrita harmônica com o tema), ícone, "palavras-chave" (tags)
  e "apelidos de estabelecimento" (merchant aliases) para a classificação automática.
- Ação "Arquivar" (não apaga histórico). Botão "Nova categoria".
- Tom: organização tranquila; sem excesso de cores berrantes.
```

### 7.13 Cartões & faturas
**Objetivo:** ver cartões e a fatura por competência. **Estados:** sem cartão; fatura fechada vs. aberta.
```text
Tela CARTÕES & FATURAS (use o tema). NÃO gere barra lateral (aside) nem cabeçalho (header) —
eles são o SHELL PADRÃO já definido no Dashboard (§7.5). Gere APENAS o conteúdo da área
principal, que será encaixado dentro desse shell (título da página: "Cartões & faturas").
- Topo: cartões cadastrados (apelido, bandeira, "fecha dia 28 / vence dia 5").
- Selecionado um cartão: FATURA por competência (seletor de mês). Cabeçalho com total da
  fatura (mono), data de fechamento e vencimento, e selo "aberta"/"fechada".
- Lista de lançamentos da fatura, estilo extrato (descrição · categoria · valor mono · parcela "2/3").
- Deixe claro que o total é calculado pelo sistema. Botão "Adicionar cartão".
```

### 7.14 Chat financeiro — *coluna fixa (3ª coluna do shell, sempre aberta)*
**Objetivo:** conversar sobre as finanças na web — como no bot — por uma **coluna de chat fixa,
sempre aberta, à direita** (a terceira coluna do shell), com o **histórico no topo**, uma
**entrada de texto** e um **anexo que aceita SOMENTE PDF**. Substitui a versão de página cheia:
esta é **a** forma do chat na web. **Estados:** vazio; PDF anexado; anexo inválido; pensando;
instabilidade/re-tentativa; fallback sem números.

> **Coluna fixa, NÃO overlay.** O chat é um **companheiro persistente** (como o bot está sempre
> a um toque): é a **terceira coluna** de um layout de três colunas — **nav** à esquerda ·
> **conteúdo da tela** no centro · **chat** à direita. Os três **coexistem**: o body reflui e
> fica **entre** a nav e o chat; nada é esmaecido, não há backdrop e a coluna **não cobre** o
> conteúdo. Por isso o prompt pede **apenas o conteúdo da coluna de chat** — como os demais
> prompts logados pedem só a área principal (§7.6–§7.17) e não redesenham o shell.
>
> **Responsividade (regra 360px).** "Sempre aberta" vale no **desktop/telas largas**; três
> colunas **não** cabem no mobile. Em tela estreita (a partir de ~1024px pra baixo) a coluna
> **recolhe** para um lançador e abre como folha por cima. Esse colapso é regra do **layout
> Blade** (`x-layouts.app`), não do desenho do rail — fica **fora** do prompt.
>
> **Anexo PDF × importação (§7.15/§7.16).** O anexo aqui é só a **afordância de entrada**: ao
> enviar um PDF, o fluxo continua na **revisão efêmera** já speccada (§7.16) — nada do documento
> é guardado (regra 6). As telas dedicadas de importar/revisar **permanecem** e só são geradas
> **após** o backend da [[spec-07-importacao-pdf]]; aqui desenhamos apenas o anexo e o retorno
> do bot com o link "Revisar importação".

> **Prompt fechado (anti-invenção).** Mesmo rigor do §7.7b: cada elemento, rótulo e valor é
> literal; há uma lista do que é **proibido** gerar; os estados extras vêm **depois**, como
> variações à parte. Cole do zero.

```text
Gere UMA tela: o CONTEÚDO de uma COLUNA FIXA de "Chat financeiro" — a terceira coluna,
à DIREITA, de um app de finanças pessoais em pt-BR. Ignore qualquer versão anterior desta
tela — comece do zero. É SÓ o conteúdo dessa coluna de chat.
Nome da tela: "Chat financeiro — coluna fixa".

━━━ CONTEXTO DE LAYOUT (NÃO redesenhe estas partes) ━━━
O app já tem um SHELL de TRÊS COLUNAS: (1) barra lateral de navegação à esquerda,
(2) o conteúdo da tela atual no centro, (3) esta COLUNA DE CHAT à direita — SEMPRE ABERTA,
fixa, coexistindo com as outras. Ela NÃO é um overlay: não há fundo esmaecido, não há
backdrop, não cobre o conteúdo. O conteúdo do centro NÃO fica apagado — os três convivem.
Gere APENAS o conteúdo da coluna de chat (itens 1 a 4 abaixo). NÃO desenhe a navegação
esquerda, o header do app, nem o conteúdo do centro.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície da coluna e bolhas: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, contagem: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cards, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples (clipe de papel, seta, documento). Sem ícones preenchidos,
  coloridos ou decorativos.

━━━ FORMATO DA COLUNA ━━━
- Coluna vertical de ALTURA TOTAL da janela, largura fixa confortável (~380px no desktop),
  superfície #FBFBF8, separada do conteúdo à esquerda por uma linha fina (#DDE0D7). Sem sombra
  forte de "flutuante" — ela é parte do layout, encostada na borda direita.
- SEM botão de fechar e SEM backdrop: a coluna está sempre presente.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere navegação, menu, abas, breadcrumb, logo, avatar, gráfico, banner promocional,
  header do app, conteúdo central, nem fundo esmaecido. SÓ a coluna de chat.
- NÃO adicione, remova nem renomeie NENHUM elemento além dos listados abaixo.
- NÃO invente valores, dicas, tooltips, ícones decorativos, "termos", nem conteúdo que não
  esteja escrito aqui entre aspas. Todo texto visível está entre aspas: use-o LITERALMENTE.
- O anexo aceita SOMENTE PDF — NÃO desenhe opção de imagem, câmera, foto, planilha nem outro tipo.

━━━ ESTRUTURA DA COLUNA (de cima para baixo) ━━━
1. CABEÇALHO da coluna: título "Chat financeiro" (Bricolage Grotesque) à esquerda. Sem botão X.
2. BANNER de transparência (discreto, uma linha, sobre superfície levemente destacada): "Respostas
   geradas com IA. Os números vêm do seu banco de dados — a IA nunca os inventa."
3. HISTÓRICO (área ROLÁVEL, ocupa a MAIOR PARTE da altura — é o topo do chat): bolhas de conversa,
   das mais antigas (em cima) às mais recentes (embaixo):
   - Bolha do USUÁRIO (à direita, neutra #EDF0E8): "Quanto gastei no mercado em junho?"
   - Bolha do ASSISTENTE (à esquerda, #FBFBF8 com borda #DDE0D7): "Você gastou R$ 1.234,56 em
     Mercado em junho." — o valor em MONO. LOGO ABAIXO da bolha, na mesma linha: um CHIP DE FONTE
     (pill, secundário) "fonte: gastos · junho · categoria Mercado · 12 registros" e um selo pill
     verde-cédula suave "número conferido".
   - Bolha do USUÁRIO com ANEXO (à direita): um chip de arquivo "fatura-nubank.pdf · PDF" (ícone
     de documento, de linha) acima do texto "Segue minha fatura".
   - Bolha do ASSISTENTE respondendo ao anexo (à esquerda): "Fatura lida ✓ Encontrei 18 lançamentos
     (R$ 4.210,00)." com um botão secundário (contorno verde-cédula) "Revisar importação". Abaixo,
     em texto secundário minúsculo: "O PDF já foi descartado."
4. ÁREA DE ENTRADA fixa no rodapé da coluna, com uma linha fina (#DDE0D7) acima:
   - Um botão de ANEXO à esquerda (ícone de clipe de papel, de linha), rótulo acessível "Anexar PDF".
   - Um campo de texto que cresce, placeholder "Pergunte sobre seus gastos…".
   - Um botão primário de ENVIAR à direita (verde-cédula, ícone de seta de linha, alvo ≥ 44px).

━━━ INVARIANTES ━━━
A interface NUNCA calcula dinheiro: todo valor na resposta vem pronto do backend; a tela só exibe.
O anexo é SOMENTE PDF e é efêmero — processado e descartado, nada do documento fica armazenado
(daí a nota "O PDF já foi descartado"). Acessível: contraste AA, foco de teclado visível (anel
verde-cédula), alvos ≥ 44px.

Entregue como UMA tela: "Chat financeiro — coluna fixa".
```

> **Estados adicionais (gerar depois, como variações à parte — não no prompt principal, com o
> mesmo rigor literal):**
> - **VAZIO (primeiro uso):** histórico só com um texto-guia calmo, sem bolhas — "Pergunte sobre
>   seus gastos ou anexe a fatura em PDF."
> - **PDF ANEXADO (pronto para enviar):** acima do campo de texto, um chip "fatura-nubank.pdf · PDF"
>   com um "✕ remover", e a nota discreta "O PDF é processado e descartado — nada fica armazenado."
> - **ANEXO INVÁLIDO:** aviso inline em argila junto à entrada — "Aceito apenas PDF."
> - **PENSANDO:** a última bolha do assistente vira um indicador sutil "consultando seus dados…".
> - **INSTABILIDADE:** aviso ocre — "Instabilidade no momento — tentando de novo…".
> - **FALLBACK (sem números):** bolha do assistente SEM nenhum número — "Não consegui confirmar os
>   números com segurança agora. Pode reformular a pergunta?"

### 7.15 Importar fatura (upload) — *gerar após backend da 07*
**Objetivo:** enviar o PDF da fatura. **Estados:** arrastando; arquivo inválido; **PDF com senha**; enviando.
```text
Tela IMPORTAR FATURA (use o tema). [Gerar somente após o backend da importação de PDF.]
NÃO gere barra lateral (aside) nem cabeçalho (header) — eles são o SHELL PADRÃO já definido no
Dashboard (§7.5). Gere APENAS o conteúdo da área principal, que será encaixado dentro desse
shell (título da página: "Importar fatura").
- Área de upload (drag-drop + "Selecionar arquivo"), aceita SOMENTE PDF.
- Aviso de privacidade em destaque: "Seu PDF é processado e descartado — nada do documento
  fica armazenado." (regra 6).
- Erros diretos: "Aceito apenas PDF."; "Este PDF está protegido por senha — envie uma versão
  sem senha."; "Não consegui ler este arquivo."
- Botão "Enviar para revisão". Estado enviando com progresso sóbrio.
```

### 7.16 Revisão da importação (lote) — *gerar após backend da 07*
**Objetivo:** revisar itens extraídos e **confirmar** (regra 7), marcando duplicados. **Estados:** com duplicados; nada para importar.
```text
Tela REVISÃO DA IMPORTAÇÃO (use o tema). [Gerar somente após o backend da importação de PDF.]
NÃO gere barra lateral (aside) nem cabeçalho (header) do app — eles são o SHELL PADRÃO já
definido no Dashboard (§7.5). Gere APENAS o conteúdo da área principal, que será encaixado
dentro desse shell (título da página: "Revisão da importação").
- Cabeçalho do conteúdo: "Encontrados 18 lançamentos · R$ 4.210,00" (mono) e "selecionados 16".
- Tabela/lista em lote: checkbox por item, descrição, valor (mono), data, parcela. Itens
  prováveis DUPLICADOS vêm desmarcados e sinalizados ("já existe nos seus lançamentos").
- Filtro "ocultar duplicados". Seleção em massa (marcar/desmarcar todos).
- Rodapé fixo: total selecionado (mono) + botões "Confirmar importação" (primário) e "Cancelar".
- Lembrete: "Nada é gravado até confirmar; o PDF já foi descartado."
- Estado "nada para importar" com texto-guia.
```

### 7.17 Configurações & privacidade
**Objetivo:** perfil, fuso, vínculo, transparência de IA, direitos LGPD. **Estados:** confirmação de exclusão.
```text
Tela CONFIGURAÇÕES & PRIVACIDADE (use o tema). NÃO gere barra lateral (aside) nem cabeçalho
(header) — eles são o SHELL PADRÃO já definido no Dashboard (§7.5). Gere APENAS o conteúdo da
área principal, que será encaixado dentro desse shell (título da página: "Configurações").
Seções claras:
- "Perfil": nome, e-mail, senha.
- "Preferências": fuso (default America/São Paulo), mês de referência.
- "Telegram": status do vínculo + atalho para a tela de vínculo.
- "IA e transparência": explica os 3 papéis da IA (classificar, extrair, redigir) e reforça
  "a IA nunca calcula dinheiro"; link para a política.
- "Privacidade (LGPD)": retenção de conversas (60 dias), "Baixar meus dados", e "Excluir minha
  conta" (ação destrutiva, com confirmação dupla e texto direto sobre o que será apagado).
Tom sóbrio e honesto; a ação destrutiva é claramente separada e em argila.
```

## 8. Mensagens do bot (texto — implementadas em código, não no Stitch)
Curtas, sem botões (salvo confirmação), pt-BR, mesma voz do §4.7. *Copy* de referência:

| # | Gatilho | Mensagem (modelo) |
|---|---|---|
| B1 | Vínculo: pedir contato | "Tudo certo! Agora toque em **Compartilhar contato** para eu confirmar seu número." |
| B2 | Vínculo concluído | "Pronto, conta conectada ✓ Pode registrar e consultar gastos por aqui." |
| B3 | Confirmação de gasto | "Gasto de **R$ 150,00** em 3x no crédito (Nubank), categoria Mercado, 1ª vence **10 de junho**. Confirma? (responda *sim* ou *não*)" |
| B4 | Esclarecimento | "Qual cartão? **Nubank** ou **Itaú**?" |
| B5 | Confirmação de edição/cancelamento | "Cancelei as parcelas futuras desse gasto. As já pagas foram preservadas." |
| B6 | Alerta de orçamento | "Você atingiu **R$ 4.000,00** do limite do mês." |
| B7 | Resposta de consulta (com fonte) | "Você gastou **R$ 1.234,56** em Mercado em junho. _(fonte: 12 lançamentos · junho)_" |
| B8 | Instabilidade / re-tentativa | "Tive uma instabilidade ao processar — já estou tentando de novo." |
| B9 | Fallback sem números | "Não consegui confirmar os números com segurança agora. Pode reformular a pergunta?" |
| B10 | Recusa (fora de escopo) | "Eu só ajudo com suas finanças — gastos, saldos, faturas e contas a vencer. Como posso ajudar nisso?" |
| B11 | Importação: resumo | "Fatura lida ✓ Encontrei **18 lançamentos** (R$ 4.210,00). Abra a revisão na web para confirmar." |
| B12 | Importação: PDF com senha | "Esse PDF está protegido por senha. Envie uma versão sem senha, por favor." |
| B13 | Ajuda (/ajuda) | "Posso registrar gastos ('gastei 50 no mercado'), e responder sobre saldo, faturas e contas a vencer. É só falar." |

> As mensagens reais saem pela porta `RespostaAoUsuario` (hoje `RespostaInerte`, ver
> [[spec-03-telegram]] §10): o frontend do bot implementa o *sender* do Telegram que formata
> o `ResultadoDaInteracao`/`RespostaDaConsulta` usando estes modelos.

## 9. Definition of Done
- [ ] Design system (§4) aplicado no projeto Stitch (prompt-base 7.0).
- [ ] Todas as telas do mini-TODO (§6) geradas, aprovadas e marcadas — exceto 15/16, que
      aguardam o backend da [[spec-07-importacao-pdf]].
- [ ] Cada tela respeita os invariantes de §3 (sem cálculo no cliente; confirmar antes de
      gravar; fonte/transparência da IA; pt-BR; acessibilidade AA/360px).
- [ ] Mensagens do bot (§8) redigidas e prontas para implementação em código.
- [ ] §10 preenchida com os artefatos reais (telas exportadas / componentes).
- [ ] Commit local atômico (regra 1: sem push); separado do backend (regra 3).

## 10. Estado atual / artefatos
- **Status:** 🟡 Em andamento — **grupo A concluído** (tema + entrada & onboarding).
- **Entregue:** **tema (§7.0)** e **grupo A geradas e aprovadas no Stitch** — §7.1 Login,
  §7.2 Criar conta, §7.3 Onboarding+LGPD, §7.4 Vínculo do Telegram. Prompts refinados (estados
  como variações, copy na voz da interface, conteúdo pt-BR realista, acessibilidade embutida).
  _(Registrar aqui os nomes dos artefatos exportados do Stitch quando disponíveis.)_
- **Dashboard / Visão geral (§7.5): 🟢 implementado em Blade (dados fake).** Shell padrão já
  extraído para o layout `x-layouts.app` (com prop `wide` para o canvas largo). O conteúdo da
  Visão geral segue fielmente o design do Stitch, com componentes reutilizáveis:
  `x-dashboard.month-ruler` (a régua do mês — ticks gerados server-side, sem JS para o layout),
  `x-dashboard.summary-card`, `x-dashboard.bill-row` e `x-ui.status-badge`; donut e FAB inline
  em `home.blade.php`. Material Symbols (CDN) do Stitch foi trocado por SVG inline no `x-icon`
  (regra 6/LGPD: sem CDN de terceiros). Tokens novos no design system: `secondary`, `tertiary`,
  `error`, `on-primary`, `surface-container-highest`. Os **três estados** do Stitch estão
  implementados: `pronto` (dados), `x-dashboard.empty-state` (vazio) e `x-dashboard.loading`
  (skeleton). O FAB "Registrar gasto" abre o modal §7.7b.
- **Dashboard — ligação ao backend: 🟢 concluída (dados reais).** `DashboardController@index`
  (rota `home`) compõe os números JÁ calculados pelo domínio (`ResumoDoMes` + as 4 consultas
  determinísticas) e apenas FORMATA em pt-BR para a tela (regra 3/5; a UI nunca calcula, regra 4).
  Preenche a régua do mês (novo domínio `App\Domain\Dashboard\DiasDeVencimentoNoMes` para os ticks
  de vencimento), os 4 cards (disponível, gastos do mês + comparativo vs. mês anterior, a vencer em
  7 dias, fatura do cartão em destaque), o donut por categoria (top 3 + "Outros") e a lista de
  próximas contas. Estado `vazio` quando o usuário não tem lançamentos; `?estado=` segue como
  afordância de revisão. Cobertura: `tests/Feature/Dashboard/DashboardTest.php` (login, vazio,
  números reais formatados, isolamento por usuário) e `tests/Feature/Domain/DiasDeVencimentoNoMesTest.php`.
  **Deferido:** navegação por competência (mês anterior/seguinte) — o resumo é ancorado no "hoje".
- **Registrar gasto — modal rápido (§7.7b): 🟢 implementado em Blade (dados fake).**
  Componente `x-modal.registrar-gasto` (`resources/views/components/modal/registrar-gasto.blade.php`),
  fiel à tela do Stitch "Registrar gasto — Crédito": mesmos estilos de campo (`.input-field`)
  e botões, prévia mono "calculada pelo sistema", chip de vencimento somente-leitura. Material
  Symbols (CDN) trocado por SVG inline no `x-icon` (regra 6/LGPD). Comportamento em
  `resources/js/pages/registrar-gasto.js` (vanilla, code-split): abrir pelo **FAB** do dashboard,
  troca de forma (crédito × à vista, com Cartão/Parcelas/prévia vs. vencimento editável +
  recorrência), seleção de categoria, **validação inline** (variação de erro em argila) e a
  **animação de salvando** (spinner + campos desabilitados). Afordância de revisão
  `?modal=aberto|erro|salvando` (mesmo padrão do `?estado` do dashboard).
- **Registrar gasto — ligação ao backend: 🟢 concluída (persiste no banco).** Borda web fina
  reusando o domínio já testado (`App\Domain\Gasto\RegistrarGastoManual`): `RegistrarGastoRequest`
  (validação + tradução form→`DadosGastoManual`, valor pt-BR→centavos, escopo por usuário),
  `GastoController@previa` (calcula sem gravar) e `@store` (grava), rotas `gastos.previa`/
  `gastos.store`. **Fluxo em dois passos (regra 7):** o formulário manda para a prévia → o backend
  calcula parcelas/vencimento/valor (regra 4, a tela nunca calcula) → o painel de confirmação mostra
  o resumo + tabela mono + aviso de duplicidade → só então grava (transaction + installments +
  auditoria, atômico). A home carrega os **cartões e categorias reais** do usuário no modal. Cobertura:
  `tests/Feature/Gasto/RegistrarGastoWebTest.php` (auth, validação, mapeamento, prévia sem persistir,
  gravação à vista e no crédito, isolamento por usuário, duplicidade). **Deferido (feature própria,
  com migration + TDD):** "data de pagamento" (marcar como pago) e recorrência — aceitos na UI, ainda
  não persistidos.
- **Registro histórico (Stitch):**
  Projeto Stitch `Caderno de Contas Sereno` (`projects/9921880157030532605`), design system
  `Caderno de Contas` (`assets/ea051d20127a4459af28c95eb27d2eb6`). Screen id
  `ac72979b888345569d88d18bfc8b974c` — "Modal Registrar Gasto - Crédito Parcelado", desktop.
  Estado principal: forma **crédito** revelando Cartão (`Nubank •••• 1234 — fecha dia 28`) +
  Parcelas 3 com **prévia mono** ("calculada pelo sistema"), chip somente-leitura "vence 5 de
  julho · calculado pelo cartão", data de pagamento opcional, switch "Repete todo mês?" e
  Categoria com selo **"sugerido"** no Mercado. Correção aplicada na 2ª iteração: seletor de
  forma de pagamento com as **5 formas distintas** (crédito · débito · pix · dinheiro · boleto),
  desfazendo o "Pix / Dinheiro" fundido e incluindo boleto. **Pendências para a ligação ao
  Blade:** trocar Material Symbols (CDN) por SVG inline (regra 6/LGPD, como no Dashboard);
  gerar as **variações de estado** ainda não desenhadas (fora-de-cartão com vencimento
  editável; crédito sem cartão cadastrado; recorrente ligado; validações inline; salvando).
- **Próximo:** grupo B (núcleo financeiro) — §7.6, §7.7 e §7.8 a §7.13. A ligação técnica das
  telas A e do Dashboard ao Laravel (Blade+Tailwind, rotas, auth, validação) fica para a etapa
  de implementação.
- **Decisão de arquitetura de UI:** o **shell (aside + header) é único** e reutilizado por
  todas as telas logadas; os prompts §7.5–§7.17 pedem **apenas o conteúdo** da área principal
  (ver nota do §5). Telas pré-login (§7.1–§7.4) não têm shell.
- **Decisão de design aplicada (§7.14, Chat financeiro):** o chat na web deixou de ser página
  cheia — e também **deixou de ser overlay/drawer**. Virou uma **coluna fixa, sempre aberta**, à
  direita: a **terceira coluna** de um layout de **três colunas** (nav · conteúdo · chat). Os três
  **coexistem** — o body reflui e fica **entre** a nav e o chat; nada é esmaecido, sem backdrop,
  a coluna **não cobre** o conteúdo (revoga a versão "painel docado sobre um app esmaecido"). O
  chat passa a ser a **terceira zona do shell** (`x-layouts.app`), presente em toda rota logada.
  **Responsividade:** "sempre aberta" vale no desktop; no mobile (≲1024px) a coluna **recolhe**
  para um lançador e abre como folha — colapso é regra do **layout Blade**, fora do prompt do
  Stitch. Prompt reescrito no estilo **fechado (anti-invenção)** do §7.7b, pedindo **apenas o
  conteúdo da coluna** (sem redesenhar o shell) e **sem** botão de fechar. O anexo é só a
  **afordância de entrada**: o PDF continua no fluxo de **revisão efêmera** (§7.16) e é descartado
  (regra 6); as telas dedicadas §7.15/§7.16 seguem no mini-TODO, geradas **após** o backend da
  [[spec-07-importacao-pdf]].
- **Decisão de design aplicada:** token `papel` ajustado `#F3F4EF` → `#EDF0E8` (§4.2 e §7.0)
  para o fundo ler claramente como verde-acinzentado e fugir do "cream" (default de IA).
- **Adiado para etapa técnica posterior:** ligação das telas ao Laravel (Inertia/Blade, dados
  reais, validação server-side) e implementação do *sender* do bot (formatação via §8).
- **Decisões de design tomadas:** conceito "caderno de contas"; assinatura "a régua do mês";
  dinheiro sempre em mono tabular; paleta verde-cédula/ocre/argila fugindo do azul de fintech
  e dos defaults de IA. *(preencher conforme gerar)*
