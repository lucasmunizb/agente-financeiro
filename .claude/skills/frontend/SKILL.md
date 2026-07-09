---
name: frontend
description: Use sempre que for construir, revisar ou otimizar o frontend deste projeto (Laravel 12 + Blade + Tailwind v4 + Vite) — telas web, ligação das telas geradas no Stitch ao Blade, layout/UX/UI, o design system "caderno de contas", acessibilidade, desempenho/Core Web Vitals (LCP, CLS, INP), carregamento de fontes, build do Vite, SEO técnico (meta/OpenGraph/dados estruturados/sitemap/robots) e privacidade na borda (LGPD: consentimento, nada sensível no cliente). Dispare quando o usuário mencionar "frontend", "tela", "view", "Blade", "Tailwind", "Vite", "UX", "UI", "design system", "Stitch", "performance"/"desempenho", "Core Web Vitals"/"Web Vitals", "LCP", "CLS", "INP", "fonte"/"font", "imagem"/"asset", "SEO", "meta tag", "OpenGraph", "dados estruturados", "sitemap", "robots", "acessibilidade" ou "LGPD na tela" — mesmo sem pedir explicitamente. NÃO use para regra financeira/cálculo (isso é backend — skill `laravel-backend`) nem para redigir as mensagens do bot.
---

# frontend — frontend deste projeto

Convenções de frontend **para ESTE projeto** (não um guia genérico de web). A apresentação
(telas web + mensagens do bot) é **etapa separada** do backend (regra inviolável 3): o
domínio + testes + bordas vêm primeiro; a tela depois, em commit próprio.

Fontes de verdade: [`docs/specs/FE-frontend-stitch.md`](../../../docs/specs/FE-frontend-stitch.md)
(design system, telas, invariantes), [`docs/05-arquitetura.md`](../../../docs/05-arquitetura.md),
[`docs/09-nfr-seguranca-lgpd.md`](../../../docs/09-nfr-seguranca-lgpd.md) e `CLAUDE.md`.

## Postura: aja como especialista sênior, não como aplicador de checklist
Ao construir/revisar uma tela, raciocine com três chapéus ao mesmo tempo e **explicite os
trade-offs** em vez de só cumprir itens:
- **UX/UI sênior:** defenda a decisão pela tarefa do usuário (lançar/entender dinheiro),
  não pelo gosto. Prefira a solução mais simples que resolve; corte fricção antes de
  adicionar UI. Justifique hierarquia, densidade e cópia.
- **Engenheiro de performance:** **meça antes de otimizar** e depois de mudar. Um número
  (LCP/CLS/INP real) vale mais que uma regra decorada — ver "laço de medição" abaixo.
- **SEO técnico:** aplique só onde há público (fora do login); nunca troque privacidade
  por alcance.
Quando duas metas colidirem (ex.: fonte bonita vs. CLS, animação vs. INP), diga qual
priorizou e por quê. O checklist no fim é o **piso**, não o teto.

## Stack
- **Laravel 12 + Blade + Tailwind v4 + Vite** (`laravel-vite-plugin`, `@tailwindcss/vite`).
- **Sem Inertia/Livewire** no MVP: Blade server-side + JS mínimo. Não adicione camada SPA
  sem decisão explícita do usuário — adiciona build, deps e custo de hidratação que este
  app (atrás de login, pouca interatividade) não precisa.
- Entradas do Vite: `resources/css/app.css`, `resources/js/app.js`. Tudo roda **em
  contêiner** (`make` / `docker compose`) — nunca instale node/npm no host (regra 9).

## Regra de ouro: o backend já existe; a tela só apresenta
- **A UI nunca calcula dinheiro** (regra 4). Totais, parcelas, saldos e "disponível do mês"
  chegam **prontos** do backend. A tela **exibe** e **captura**; não soma, não projeta, não
  recalcula em JS.
- **Centavos no backend; pt-BR só na borda** (regra 5). Formate `R$ 1.234,56`, `10 de junho`,
  `3x` na exibição. Fuso **America/Sao_Paulo**.
- **Confirmar antes de gravar** (regra 7): todo registro/edição/cancelamento mostra uma
  **prévia** e exige confirmação explícita. Auto-save é proibido no MVP.
- **Nada sensível persiste no cliente** (regra 6): PDF/texto extraído é efêmero; a revisão
  de importação trabalha sobre dados em memória da sessão, descartados ao final.

## Ligação das telas do Stitch ao Blade
As telas nascem no **Stitch** (design-first, ver spec FE §5–§7). Ao trazê-las para o código:
1. Extraia o design para **componentes Blade** (`resources/views/components/…`) — um por
   elemento recorrente do spec §4.6 (card de resumo, chip de categoria, selo de status,
   linha de lançamento, banner de transparência de IA, estados vazio/carregando/erro).
2. Use **layout + slots** Blade; não duplique chrome. Telas pré-login (login, criar conta,
   onboarding, vínculo) não têm a barra de navegação do app.
3. Traga os **tokens do design system** para o CSS como **variáveis/utilitários Tailwind**
   (ver design system abaixo) — não espalhe hex solto pelas views.
4. Ligue a dados reais via controller/Form Request (validação **server-side**), nunca via
   cálculo no cliente.

## Design system "caderno de contas" (resumo — detalhe no spec FE §4)
- **Conceito:** precisão de um extrato com a calma de um caderno. Dinheiro é **dado tabular
  monoespaçado, alinhado à direita por coluna** — esta é a assinatura visual.
- **Paleta (modo dia):** `tinta #1C1B17` · `papel #EDF0E8` · `superficie #FBFBF8` ·
  `cedula #1F6E5A` (primária) / `cedula-clara #2E8B72` · `ocre #C9852A` (a vencer) ·
  `argila #B4452F` (em atraso, uso parco) · `linha #DDE0D7` · `nevoa #6B6F66`.
- **Tipografia:** *Bricolage Grotesque* (títulos, com moderação) · *IBM Plex Sans* (UI) ·
  *IBM Plex Mono* (todo R$, data, %, parcela — mono, alinhado à direita).
- **Forma:** raio 12px (cartões) / 8px (inputs/botões) / pill (chips). Sombras difusas.
  Motion comedido; respeite `prefers-reduced-motion`.
- **Elemento-assinatura:** "a régua do mês" (spec §4.5) no Dashboard.

Defina os tokens uma vez no `resources/css/app.css` (Tailwind v4 usa `@theme`):
```css
@theme {
  --color-tinta: #1C1B17;   --color-papel: #EDF0E8;   --color-superficie: #FBFBF8;
  --color-cedula: #1F6E5A;  --color-cedula-clara: #2E8B72;
  --color-ocre: #C9852A;    --color-argila: #B4452F;
  --color-linha: #DDE0D7;   --color-nevoa: #6B6F66;
  --font-display: "Bricolage Grotesque", sans-serif;
  --font-sans: "IBM Plex Sans", system-ui, sans-serif;
  --font-mono: "IBM Plex Mono", ui-monospace, monospace;
  --radius-card: 12px; --radius-control: 8px;
}
```

## UX/UI — invariantes de toda tela (spec FE §3)
- **Transparência de IA:** toda resposta gerada por IA exibe a **fonte** (período, filtros,
  nº de registros) e o selo **"número conferido"**; em degradação, fallback **sem números**.
- **Escopo estrito por usuário:** nenhuma tela mostra dado de terceiros; "não encontrado"
  nunca revela existência de dados de outra conta.
- **Estados sempre tratados:** vazio (texto-guia que convida à ação), carregando (esqueleto
  sóbrio ou rótulo no botão), erro (o que houve + como resolver, na voz da interface, sem
  desculpas vagas).
- **Voz (copy):** pt-BR, sentence case, verbos diretos, sem jargão técnico. Uma ação mantém
  o mesmo nome do botão ao toast ("Confirmar" → "Confirmado").

## UX de formulários — o coração do app (lançar/editar dinheiro)
A tarefa central é digitar valores no celular. Um formulário ruim aqui custa cada
lançamento; trate isto como prioridade de UX, não detalhe.
- **Teclado certo no mobile:** valores usam `inputmode="numeric"` (ou `decimal`); data usa
  o controle nativo apropriado. Teclado errado é o atrito nº 1 em app financeiro.
- **`autocomplete`/`name` corretos** (e-mail, senha, nome) para o preenchimento do
  navegador funcionar; senha nova com `autocomplete="new-password"`.
- **Máscara pt-BR na borda, centavos por baixo** (regra 5): o usuário vê `R$ 1.234,56`; o
  submit envia inteiro em centavos. Nunca faça o cálculo/parse virar cálculo de dinheiro no
  cliente — só formatação de exibição.
- **Erro inline, junto do campo**, no submit ou ao sair do campo — não um alerta genérico
  no topo. Diga o que corrigir, na voz da interface. Validação real é **server-side** (Form
  Request); a do cliente é só cortesia de latência.
- **Ordem de tabulação lógica**, um `<label>` real por campo, `autofocus` no primeiro campo
  relevante, `enter` submete. Botão primário desabilitado enquanto envia (rótulo vira
  "Salvando…"), evitando duplo-submit.
- **Confirmação com prévia** antes de gravar (regra 7): mostre o que será salvo, não só
  "tem certeza?".

## Hierarquia visual e mobile-first (heurística de decisão)
- **Mobile-first, sempre.** Projete a 360px primeiro; o desktop é o caso fácil. Se não cabe
  no celular, a tela tem informação demais — corte ou use **progressive disclosure**
  (detalhes sob toque/expansão), não fonte menor.
- **Um foco por tela.** Cada tela tem uma ação/uma leitura principal; ela ganha o maior
  peso visual (tamanho, contraste, posição acima da dobra). O resto recua. No app, o número
  em mono tabular costuma ser o herói — deixe-o respirar.
- **Densidade a serviço da leitura:** tabelas de dinheiro podem ser densas (é o extrato),
  mas ações e navegação precisam de espaço e alvos ≥44px. Não misture as duas densidades no
  mesmo bloco.
- **Feedback imediato, sem otimismo enganoso:** clique responde em <100ms (estado do botão,
  esqueleto); como só grava após confirmação (regra 7, sem auto-save), **não** finja
  sucesso antes da resposta do servidor. Micro-interações dentro do orçamento de motion
  (`prefers-reduced-motion`).

## Desempenho / Core Web Vitals
Mire **LCP < 2,5s**, **CLS < 0,1**, **INP < 200ms**. O maior risco aqui são as **3 fontes**.

### Laço de medição (meça antes e depois de otimizar)
Regra não vale nada sem número: capture uma métrica **antes** de mexer, mude uma coisa,
meça de novo. Otimizar no escuro adiciona complexidade sem prova.
- **Lighthouse** (aba Performance do Chrome DevTools, modo mobile + throttling) para uma
  leitura rápida de LCP/CLS/TBT por tela.
- **Playwright em contêiner** para medir render/scroll de forma reprodutível (app em
  `app:8000`, headless por software; `/tmp` não monta) — receita já registrada na memória
  do projeto. Use quando suspeitar de jank de scroll ou custo de `backdrop-filter`.
- **CLS na prática:** recarregue com throttling e observe se o swap de fonte ou imagem sem
  dimensão empurra o layout. Se empurrar, corrija a causa (métrica de fallback / `width`
  `height`), não esconda com animação.

### Fontes (principal causa de CLS/LCP neste projeto)
- **Auto-hospede** as fontes (via Vite/Fontsource ou arquivos em `resources/fonts/`); não
  dependa de CDN externo de terceiros (latência + privacidade/LGPD).
- **`font-display: swap`** para não bloquear a renderização (evita FOIT).
- **`<link rel="preload">`** apenas as fontes do **above-the-fold** (ex.: IBM Plex Sans
  regular + IBM Plex Mono regular). Não pré-carregue pesos que não aparecem na primeira tela.
- **Limite pesos/estilos** ao que o design usa (ex.: Sans 400/600, Mono 400/500, Display
  600). Cada peso é um arquivo a baixar.
- **Subset latin** (o app é pt-BR); descarte ranges não usados.
- Reserve métrica de fonte (`size-adjust`/fontes fallback equivalentes) para evitar **CLS**
  no swap.

### Vite / build
- Sirva sempre via `@vite([...])` no Blade (hashing + cache busting automáticos).
- Mantenha `app.js` enxuto; **code-split** o que for específico de tela (`import()`
  dinâmico) em vez de um bundle único gordo.
- Em produção, rode `make` → `vite build` (assets minificados, tree-shaken). Não envie JS de
  dev para produção.
- Tailwind v4 já purga CSS por uso — não crie utilitários mortos.

### Imagens e mídia
- **SVG** para ícones (chips de categoria, selos); evite icon-fonts pesadas.
- Imagens reais: formatos modernos (AVIF/WebP), `width`/`height` **sempre** definidos
  (evita CLS), `loading="lazy"` fora do above-the-fold, `decoding="async"`.
- Não há imagens de fatura/PDF na UI persistidas (regra 6).

### JS e interação
- Prefira HTML/CSS nativo (details/summary, dialog, form validation) antes de JS.
- Para reatividade pontual, **Alpine.js** é suficiente; evite framework SPA (ver Stack).
- Cuidado com **INP**: handlers curtos, sem trabalho pesado síncrono no clique.

## SEO técnico — só onde faz sentido
O app é **majoritariamente atrás de login**: SEO clássico só pesa nas **páginas públicas**
(landing, login, criar conta, política de privacidade, termos).

- **Páginas autenticadas:** `<meta name="robots" content="noindex, nofollow">` — não devem
  ser indexadas (privacidade + nada de conteúdo público útil).
- **Páginas públicas:** `<title>` e `<meta name="description">` por página (use uma stack de
  Blade — `@section('title')`), **OpenGraph** (`og:title`, `og:description`, `og:image`,
  `og:type`, `og:url`) + Twitter Card para compartilhamento decente.
- **`canonical`** na home pública; **`lang="pt-BR"`** no `<html>`; **`<meta name="viewport">`**
  (largura do dispositivo) em toda página — base de mobile + CWV.
- **HTML semântico:** um único `<h1>` por página e hierarquia de headings sem pular níveis;
  `<main>/<nav>/<header>` como landmarks. Serve a SEO **e** a leitores de tela.
- **`sitemap.xml`** e **`robots.txt`** só com as rotas públicas (bloqueie `/app`, webhook
  etc.); o webhook do Telegram nunca é indexável.
- **Dados estruturados (JSON-LD)** só se houver conteúdo público que se beneficie (ex.:
  `Organization`/`WebSite` na landing). Não invente schema para telas privadas.
- **Não** exponha dados financeiros, e-mail ou identificadores em meta tags/OG.

## LGPD na borda (frontend)
- **Consentimento explícito** na tela de onboarding antes de usar (spec FE §7.3 / doc 09):
  botão de continuar **desabilitado** até marcar o consentimento; link para a política.
- **Transparência de IA** visível (banner: "os números vêm do seu banco de dados — a IA
  nunca os inventa").
- **Nada sensível no cliente:** não jogue PDF/texto extraído em `localStorage`, query string
  ou logs de front; a revisão de importação é efêmera (memória de sessão).
- **Direitos do titular** acessíveis: "baixar meus dados" e "excluir conta" nas Configurações
  (ação destrutiva separada, em `argila`, com confirmação dupla).
- **Sem trackers de terceiros** que vazem dados pessoais; fontes auto-hospedadas (ver acima).

## Acessibilidade (piso de qualidade — não-negociável)
- Responsivo até **360px**; alvos de toque **≥ 44px**.
- **Foco de teclado visível** (anel `cedula`); navegação por teclado em todos os controles.
- Contraste **WCAG AA**; não comunique status só por cor (use ícone/rótulo junto).
- `prefers-reduced-motion` respeitado; `alt` em imagens; labels reais em inputs.
- **Diálogos/modais** (`<dialog>` nativo de preferência): foco entra ao abrir, fica preso
  dentro, volta ao gatilho ao fechar; `esc` fecha. Erro anunciado (`aria-live`) para leitor
  de tela, não só visualmente.

## Checklist de uma tela pronta (Definition of Done)
- [ ] Tokens do design system aplicados (sem hex solto); mono tabular nos valores.
- [ ] Estados vazio/carregando/erro tratados; copy na voz da interface.
- [ ] Zero cálculo de dinheiro no cliente; valores vêm prontos (regra 4/5).
- [ ] Formulário: teclado mobile certo (`inputmode`), `autocomplete`, erro inline,
      anti-duplo-submit, máscara pt-BR só na exibição.
- [ ] Mobile-first a 360px; um foco principal por tela; hierarquia clara.
- [ ] Confirmação antes de gravar, com prévia (regra 7); sem auto-save.
- [ ] Transparência de IA (fonte + "número conferido") onde houver resposta de IA.
- [ ] Fontes auto-hospedadas, `font-display: swap`, preload só do above-the-fold.
- [ ] Imagens com dimensão + lazy; JS code-split; build via `@vite`.
- [ ] CWV medido (Lighthouse/Playwright) antes/depois de otimização relevante.
- [ ] `noindex` se autenticada; meta/OG + viewport + `<h1>` único se pública; nada sensível em meta/OG.
- [ ] Acessível (360px, foco visível, AA, alvos ≥ 44px, reduced-motion, foco preso em modal).
- [ ] Commit **local** separado do backend (regras 1 e 3); nenhum segredo/PDF commitado.

## Validação (rode mentalmente antes de entregar)
- "Esse número apareceu calculado no front?" → se sim, está errado; traga pronto do backend.
- "Essa tela privada está `noindex`?" → telas atrás de login nunca indexam.
- "O swap de fonte mexe o layout?" → ajuste métrica de fallback para CLS ~0.
- "Adicionei dep de SPA/CDN externo?" → reconsidere; o MVP é Blade + Vite + fontes locais.
