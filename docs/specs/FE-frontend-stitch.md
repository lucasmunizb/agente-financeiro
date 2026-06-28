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

## 6. Mini-TODO — telas a gerar
> Marque conforme gerar/aprovar. Telas de importação dependem do backend da [[spec-07-importacao-pdf]].

**Tema**
- [ ] 0. Prompt-base de tema aplicado no projeto Stitch

**A. Entrada & onboarding**
- [ ] 1. Login
- [ ] 2. Criar conta
- [ ] 3. Onboarding + consentimento LGPD
- [ ] 4. Vínculo do Telegram

**B. Núcleo financeiro**
- [ ] 5. Dashboard do mês *(tela-assinatura: a régua do mês)*
- [ ] 6. Lançamentos — lista
- [ ] 7. Lançamento — criar/editar (com prévia de parcelas)
- [ ] 8. Lançamento — detalhe (parcelas + status)
- [ ] 9. Confirmações pendentes *(espelho web do "Confirma?" — regra 7)*
- [ ] 10. Receitas
- [ ] 11. Orçamento do mês
- [ ] 12. Categorias
- [ ] 13. Cartões & faturas

**C. IA**
- [ ] 14. Chat financeiro *(fonte/trace + estados)*

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

### 7.5 Dashboard do mês — *tela-assinatura*
**Objetivo:** leitura instantânea do mês. **Estados:** vazio (primeiro mês); carregando.
```text
Tela DASHBOARD DO MÊS (use o tema). ESTA É A TELA-ASSINATURA. Mobile-first, vira grade no desktop.

ELEMENTO-ASSINATURA — "A RÉGUA DO MÊS": uma régua horizontal do dia 1 ao último dia do mês,
com um marcador do "hoje", TICKS nas datas de vencimento (em ocre) e uma faixa sutil mostrando
o "disponível" diminuindo ao longo do mês. Posicione logo abaixo do cabeçalho. É o herói visual.

CABEÇALHO: seletor de mês ("Junho de 2026", com setas) à esquerda.

CARDS DE RESUMO (valores em mono, alinhados à direita):
- "Disponível do mês" — destaque, R$ 2.480,00 (verde-cédula se positivo).
- "Gastos do mês" — R$ 3.120,00, com mini-comparativo discreto vs. mês anterior.
- "A vencer (7 dias)" — R$ 540,00 em ocre, "3 contas".
- "Fatura do cartão" — "Nubank · fecha 28 de junho" R$ 1.870,00.

GRÁFICO: donut "gastos por categoria" com legenda (Mercado, Transporte, Restaurante, Moradia,
Lazer) — cada uma com sua cor/ícone de chip.

LISTA: "Próximas contas" (3 linhas: descrição · valor mono · "vence 30 de junho" · selo status).

Ação flutuante "Registrar gasto". Estado vazio: convite "Registre seu primeiro gasto" + dica
de usar o Telegram. Nada pisca; a régua é a única coisa que se move (animação sutil de entrada).
```

### 7.6 Lançamentos — lista
**Objetivo:** ver/filtrar/agir sobre lançamentos. **Estados:** vazio; filtro sem resultado; carregando.
```text
Tela LANÇAMENTOS (use o tema). Estilo EXTRATO: denso, legível, valores em mono à direita.
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
Tela CRIAR/EDITAR LANÇAMENTO (use o tema). Formulário calmo, um campo por linha no mobile.
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

### 7.8 Lançamento — detalhe (parcelas + status)
**Objetivo:** ver um lançamento e suas parcelas. **Estados:** com parcela paga (edição bloqueada).
```text
Tela DETALHE DO LANÇAMENTO (use o tema).
- Cabeçalho: descrição, chip de categoria, valor total (mono) e selo de status.
- Metadados: forma/cartão, data, origem ("manual" ou "Telegram" ou "fatura PDF").
- TABELA DE PARCELAS (mono): nº, valor, vencimento, status de cada uma (aberto/pago/atraso).
- Ações: "Editar" e "Cancelar". Se houver parcela paga, "Editar" fica desabilitado com a
  explicação "Há parcelas pagas — não é possível editar; você pode cancelar as futuras."
- Tudo é leitura de dados prontos; a tela não recalcula nada.
```

### 7.9 Confirmações pendentes — *espelho web do "Confirma?"*
**Objetivo:** materializar a regra 7 na web (gastos interpretados aguardando "sim"). **Estados:** vazio.
```text
Tela CONFIRMAÇÕES PENDENTES (use o tema). Lista de itens interpretados (ex.: vindos do
Telegram) aguardando confirmação antes de gravar.
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
Tela RECEITAS (use o tema).
- Resumo do mês: "Receitas de junho" R$ 6.500,00 (mono).
- Filtro por tipo: fixa / variável.
- Lista: descrição · tipo · valor (mono) · data/competência.
- Botão "Adicionar receita" abre formulário simples (descrição, valor, tipo, data) com
  "Revisar e confirmar". Sem cálculo no cliente.
```

### 7.11 Orçamento do mês
**Objetivo:** ver limite e consumo (total + por categoria). **Estados:** sem orçamento definido; estouro.
```text
Tela ORÇAMENTO DO MÊS (use o tema).
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
Tela CATEGORIAS (use o tema).
- Grade/lista de categorias, cada uma com seu chip (cor + ícone) e contagem de uso.
- Editar: nome, cor (paleta restrita harmônica com o tema), ícone, "palavras-chave" (tags)
  e "apelidos de estabelecimento" (merchant aliases) para a classificação automática.
- Ação "Arquivar" (não apaga histórico). Botão "Nova categoria".
- Tom: organização tranquila; sem excesso de cores berrantes.
```

### 7.13 Cartões & faturas
**Objetivo:** ver cartões e a fatura por competência. **Estados:** sem cartão; fatura fechada vs. aberta.
```text
Tela CARTÕES & FATURAS (use o tema).
- Topo: cartões cadastrados (apelido, bandeira, "fecha dia 28 / vence dia 5").
- Selecionado um cartão: FATURA por competência (seletor de mês). Cabeçalho com total da
  fatura (mono), data de fechamento e vencimento, e selo "aberta"/"fechada".
- Lista de lançamentos da fatura, estilo extrato (descrição · categoria · valor mono · parcela "2/3").
- Deixe claro que o total é calculado pelo sistema. Botão "Adicionar cartão".
```

### 7.14 Chat financeiro — *com fonte/trace + estados*
**Objetivo:** perguntar sobre as finanças e receber resposta **rastreável**. **Estados:** pensando; instabilidade/re-tentativa; fallback sem números.
```text
Tela CHAT FINANCEIRO (use o tema). Conversa calma, foco no conteúdo.
- Banner discreto no topo: "Respostas geradas com IA. Os números vêm do seu banco de dados —
  a IA nunca os inventa."
- Bolhas: do usuário (à direita, neutras) e do assistente (à esquerda). Na resposta do
  assistente, todo valor em MONO; abaixo da bolha, um CHIP DE FONTE: "fonte: gastos · junho ·
  categoria Mercado · 12 registros" e um selo "número conferido".
- Exemplo de resposta: "Você gastou R$ 1.234,56 em Mercado em junho."
- Estado PENSANDO: indicador sutil "consultando seus dados…".
- Estado INSTABILIDADE: aviso ocre "Instabilidade no momento — tentando de novo…".
- Estado FALLBACK: bolha SEM números "Não consegui confirmar os números com segurança agora.
  Pode reformular a pergunta?" (sem inventar valor).
- Campo de entrada fixo embaixo com placeholder "Pergunte sobre seus gastos…".
```

### 7.15 Importar fatura (upload) — *gerar após backend da 07*
**Objetivo:** enviar o PDF da fatura. **Estados:** arrastando; arquivo inválido; **PDF com senha**; enviando.
```text
Tela IMPORTAR FATURA (use o tema). [Gerar somente após o backend da importação de PDF.]
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
- Cabeçalho: "Encontrados 18 lançamentos · R$ 4.210,00" (mono) e "selecionados 16".
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
Tela CONFIGURAÇÕES & PRIVACIDADE (use o tema). Seções claras:
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
- **Status:** 🟡 Em andamento — **grupo A** (entrada & onboarding).
- **Entregue:** prompts de Stitch **refinados e prontos para colar** do **tema (§7.0)** e do
  **grupo A** — §7.1 Login, §7.2 Criar conta, §7.3 Onboarding+LGPD, §7.4 Vínculo do Telegram
  (estados explícitos como variações, copy na voz da interface, conteúdo pt-BR realista,
  acessibilidade embutida). Geração/aprovação visual no Stitch é ação externa do usuário —
  marcar os itens 0–4 do §6 conforme gerar/aprovar.
- **Decisão de design aplicada:** token `papel` ajustado `#F3F4EF` → `#EDF0E8` (§4.2 e §7.0)
  para o fundo ler claramente como verde-acinzentado e fugir do "cream" (default de IA).
- **Adiado para etapa técnica posterior:** ligação das telas ao Laravel (Inertia/Blade, dados
  reais, validação server-side) e implementação do *sender* do bot (formatação via §8).
- **Decisões de design tomadas:** conceito "caderno de contas"; assinatura "a régua do mês";
  dinheiro sempre em mono tabular; paleta verde-cédula/ocre/argila fugindo do azul de fintech
  e dos defaults de IA. *(preencher conforme gerar)*
