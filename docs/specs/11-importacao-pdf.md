# Spec 11 — Importação de PDF (Itaú) — 1ª etapa do pós-MVP

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra inviolável 2), **backend antes do
> frontend** (regra 3). Em qualquer dúvida de regra, o **escopo final** e os
> `docs/` de referência **prevalecem** sobre suposições — não invente regra financeira.
>
> Um spec é "vivo": ao concluir, marque o status, preencha **§10 Estado atual** com os
> artefatos reais (arquivos, comandos) e registre as decisões de regra que você tomou.
>
> **Spec prospectivo.** Nada aqui está implementado ainda. Os contratos de §6 e o plano
> de §7 são **proposta** test-first; ao implementar, confirme as assinaturas e atualize
> §10.
>
> **Fora do MVP (decisão de escopo).** A importação de fatura em PDF foi **removida do MVP
> e promovida à 1ª etapa do pós-MVP** (alto valor / alto risco). Ver
> [`ROADMAP-POS-MVP.md`](../ROADMAP-POS-MVP.md) (ordem 1). O pipeline efêmero base já está
> pronto e testado; o que resta (regra de extração do `ParserItau` + telas) é executado na
> primeira evolução após o fechamento do MVP.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Pós-MVP · 1ª etapa |
| **Status** | 🟡 Pipeline pronto e testado — **falta só a regra de extração** do `ParserItau` (deliberadamente adiada para depois do frontend) |
| **Depende de** | [[spec-01-dominio-financeiro]] · [[spec-03-telegram]] |
| **Habilita** | — (1ª evolução após o MVP; abre caminho para OCR avançado) |
| **Fonte de verdade** | seção 8 do escopo · [`docs/07-importacao-pdf.md`](../07-importacao-pdf.md) · [`docs/04-modelo-dados.md`](../04-modelo-dados.md) |
| **Regras críticas** | **4** (IA não calcula) · **5** (centavos) · **6** (PDF/texto/sensível nunca persistidos) · **7** (confirmar antes de gravar) |

---

## 1. Objetivo
Permitir que o usuário envie a **fatura de cartão em PDF** (Itaú, banco inicial) e receba uma
**pré-importação revisável** dos lançamentos extraídos — para confirmar (total ou
parcialmente) ou cancelar — sem que o **PDF ou o texto extraído** sejam jamais
persistidos e sem reter **nenhum dado sensível**.

## 2. Escopo
- **Inclui (backend desta etapa):**
  - Pipeline efêmero de 8 passos rodando **no `worker`, via fila** (fora do request).
  - Recepção do arquivo + **deduplicação pelo hash do nome** contra `invoice_imports`.
  - **Bloqueio de PDF com senha** — por LGPD, **não** solicitar a senha; pedir versão sem.
  - Extração da **camada de texto**; **OCR (Tesseract)** como fallback quando não há texto.
  - **Parser Itaú** determinístico extraindo descrição, valor (centavos), data e parcelas,
    **ignorando** todo dado sensível (nome, endereço, CPF, nascimento).
  - **Pré-importação** (`pendente_revisao`) que **não entra em cálculo** até a confirmação.
  - **Duplicidade** por valor + descrição + data + nº de parcelas (**nunca** a parcela
    atual), reusando o detector do Bloco 1, + **conciliação** com gastos via Telegram.
  - **Efetivação** dos itens confirmados (reusando o motor do Bloco 1) e **descarte**
    imediato do PDF e do texto.
  - Registro de **erros de parsing** em `pdf_parse_errors` para evoluir o parser.
- **Não inclui (outro spec / frontend / pós-MVP):**
  - **Tela web de revisão em lote** e **resumo/confirmação no bot** → frontend (§8).
  - Outros bancos além de Itaú (o pipeline nasce **extensível**, mas só Itaú nesta 1ª etapa).
  - **Juros, IOF, multas, encargos, compra internacional/moeda** → pós-MVP.
  - Vetorização/RAG da fatura → **fora do escopo** (conflita com a não retenção).

## 3. Cenários de aceite (Given-When-Then)
Os comportamentos verificáveis. São a base dos testes de §7.

- **C1 (recepção + dedupe) — Dado** um PDF cujo hash do nome **já consta** em
  `invoice_imports` do usuário, **Quando** ele é recebido, **Então** o sistema **avisa que
  já houve importação com esse arquivo** e só prossegue mediante confirmação explícita
  (não reprocessa silenciosamente).
- **C2 (PDF com senha) — Dado** um PDF **protegido por senha**, **Quando** entra no
  pipeline, **Então** o sistema **não pede a senha**, registra falha amigável e **solicita
  uma versão sem senha** — nada é persistido.
- **C3 (texto nativo) — Dado** um PDF Itaú **com camada de texto**, **Quando** processado,
  **Então** os lançamentos são extraídos pelo parser **sem acionar OCR**.
- **C4 (OCR fallback) — Dado** um PDF Itaú **sem texto selecionável** (imagem), **Quando**
  processado, **Então** o **OCR Tesseract** gera o texto e o parser extrai os lançamentos.
- **C5 (ignora sensível) — Dado** um texto de fatura contendo nome, endereço, CPF e
  nascimento, **Quando** o parser roda, **Então** a pré-importação contém **apenas**
  descrição/valor/data/parcelas e **nenhum** campo sensível em lugar algum.
- **C6 (pré-importação inerte) — Dado** o parsing concluído, **Quando** a pré-importação é
  montada, **Então** ela fica `pendente_revisao` e **não entra em nenhum cálculo** (saldo,
  disponível, faturas) enquanto não for confirmada.
- **C7 (duplicidade) — Dado** um item cuja chave (valor + descrição + data + nº parcelas)
  **já existe** nos lançamentos do usuário, **Quando** a pré-importação é montada, **Então**
  o item é **marcado como duplicado** (não pela parcela atual) e **informado** ao usuário.
- **C8 (confirmação parcial) — Dado** uma pré-importação revisada, **Quando** o usuário
  confirma **apenas um subconjunto**, **Então** somente esses itens são efetivados (origem
  `pdf`), os demais são descartados e **nada** é gravado sem aceite (regra 7).
- **C9 (descarte) — Dado** o fim do processamento (sucesso, cancelamento **ou** erro),
  **Quando** o job termina, **Então** o **PDF e o texto extraído são descartados** e em
  `invoice_imports` permanecem **só metadados** (hash do nome, status).
- **C10 (erro de parsing) — Dado** um trecho que o parser não reconhece, **Quando** falha,
  **Então** registra-se uma entrada em `pdf_parse_errors` (sem dado sensível) para evolução
  do parser, sem abortar os itens já reconhecidos.

## 4. Barreiras e invariantes
Regras invioláveis que esta etapa **precisa** garantir (teste dedicado para cada uma):

- **Regra 6 — não retenção.** O PDF e o texto **nunca tocam disco persistente** nem o
  banco; vivem só em memória/arquivo temporário do job e são apagados ao final, **inclusive
  em caminho de erro** (`finally`). **Nenhum dado sensível** (nome, endereço, CPF,
  nascimento) é extraído, logado ou persistido. `pdf_parse_errors` e `audit_log` guardam
  **apenas** metadados não sensíveis.
- **Regra 4 — IA não calcula.** A extração de **números** (valores, datas, nº de parcelas)
  é **determinística (parser/regex/tabela)**. A IA, se usada, só **interpreta/classifica**
  (ex.: sugerir categoria) sobre números já extraídos — nunca produz valor monetário.
- **Regra 5 — centavos.** Todo valor é **BIGINT em centavos**; parsing de `1.234,56` →
  `123456` na borda do parser. Formatação pt-BR só na exibição (frontend).
- **Regra 7 — confirmar antes de gravar.** Nada é efetivado sem aceite explícito; a
  pré-importação é `pendente_revisao` e inerte até a confirmação (total/parcial/cancela).
- **Efêmero / fora do request.** Todo o pipeline roda no **`worker`** por fila; o request
  HTTP/webhook apenas enfileira. Escopo **estrito por `user_id`** em toda consulta.

## 5. Modelo de dados
Tabelas **novas** (migrations idempotentes; par com `dba-postgres`). **O PDF não é salvo.**

- **`invoice_imports`** — controle da pré-importação. Só **metadados**:
  - `id`, `user_id` (FK), `card_id?` (FK), `hash_arquivo_nome` (hash do **nome** do
    arquivo, não do conteúdo), `status` (FK/enum: `pendente_revisao` → `confirmada` /
    `parcial` / `cancelada` / `erro`), `criado_em`/timestamps.
  - **Índice/UNIQUE** sugerido `(user_id, hash_arquivo_nome)` para a deduplicação de C1.
  - **Nunca** colunas com PDF, texto extraído ou dado sensível.
- **`pdf_parse_errors`** — bancos + erros para evoluir o parser:
  - `banks` (referência: `id`, `codigo`/`nome`, ex.: `itau`).
  - `pdf_parse_errors` (`id`, `bank_id` FK, `descricao_erro`, `criado_em`) com **relação
    N:N** banco↔erro (tabela de junção) conforme doc 04. Sem trecho sensível do PDF.
- **Reuso (sem alteração estrutural):** `transactions` (origem **`pdf`**), `installments`,
  `invoices`, `status_pagamento` (já inclui `pendente_revisao`), `audit_log`.

## 6. Contratos do domínio
Proposta extensível em **`app/Domain/Importacao/`** (confirme/ajuste ao implementar). O
parser é **por banco** atrás de uma interface, para abrir caminho a outros bancos pós-Itaú.

- **`ValidadorDeArquivo`** — `validar(string $nomeArquivo, string $conteudo): ResultadoValidacao`.
  Calcula `hash_arquivo_nome`; detecta **PDF protegido por senha** (→ recusa, C2); checa
  dedupe contra `invoice_imports` do usuário (C1). **Não** abre conteúdo sensível.
- **`ExtratorDeTexto`** — `extrair(string $pdf): TextoExtraido`. Tenta a **camada de
  texto** (proposta de lib: `smalot/pdfparser`); se vazia/imagem, delega ao fallback.
- **`OcrFallback`** — `ocr(string $pdf): TextoExtraido`. Aciona **Tesseract (pt)** embutido
  no worker (proposta: `thiagoalessio/tesseract_ocr` ou `spatie/pdf-to-text`/`pdftotext`).
- **`ParserDeFatura` (interface)** + **`ParserItau`** —
  `interpretar(TextoExtraido $texto): array<int, LancamentoExtraido>`. Determinístico
  (regex/âncoras), **ignora dado sensível**, devolve `descricao`, `valorCents` (int),
  `data` (`CarbonImmutable`), `parcelas` (int). Erros → coleta para `pdf_parse_errors`.
- **`LancamentoExtraido`** (VO) — `descricao`, `valorCents`, `data`, `parcelas`. Sem campos
  sensíveis por construção.
- **`PreImportacao`** (VO) — agrega `LancamentoExtraido[]` + marcação de **duplicados** +
  status `pendente_revisao`; inerte (não calcula dinheiro).
- **`DetectorDeDuplicidadeNaImportacao`** — **reusa** `App\Domain\Duplicidade\`:
  monta `ChaveDeDuplicidade::de($valorCents, $descricao, $data, $parcelas)` por item e
  `DetectorDeDuplicidade::apenasNovos($candidatos, $existentes)` contra os lançamentos do
  usuário (mesmo padrão de consulta de `RegistrarGastoManual::ehDuplicado`). **Nunca** a
  parcela atual. Faz a **conciliação** com gastos `origem in (telegram, manual)`.
- **`SugeridorDeCategoria`** (opcional) — reusa `App\Domain\Categoria\LookupDeCategoria::para($userId, $descricao)`
  para pré-preencher categoria de forma **determinística**.
- **`EfetivarImportacao`** — `confirmar(int $importId, array $itensAceitos, int $userId): void`.
  Para cada item aceito, **reusa o motor do Bloco 1** — `App\Domain\Parcelamento\GeradorDeParcelas::gerar()`
  e a persistência via `App\Domain\Gasto\RegistrarGastoManual::confirmar(DadosGastoManual)`
  com `origem = 'pdf'` (estender o DTO/serviço para aceitar a origem) — vincula à `invoice`,
  grava `audit_log`, atualiza `invoice_imports.status` e **descarta** PDF/texto.
- **Job/borda:** `ImportarFaturaJob` (fila, `worker`) orquestra
  Validador → Extrator/OCR → Parser → Detector → `PreImportacao`; **`finally` apaga o
  arquivo temporário**. A IA, se entrar (sugestão de categoria), é via **Laravel AI SDK**
  com guard determinístico — nunca calcula número.

## 7. Plano de testes (test-first — devem falhar primeiro)
1. **Unitários do domínio:**
   - `ParserItau` sobre **fixtures de texto** (não PDFs reais; só strings de fatura) →
     extrai descrição/valor/data/parcelas corretos; **valor em centavos** (`1.234,56`→`123456`);
     **multilinha e parcelado** (`PARC 03/10`); **ignora** linhas sensíveis (nome/CPF/endereço).
   - `ValidadorDeArquivo` → **detecta senha** (recusa, C2); **hash do nome** estável; dedupe.
   - `DetectorDeDuplicidadeNaImportacao` → marca duplicado por valor+descrição+data+parcelas;
     **nunca** pela parcela atual; conciliação com gastos do Telegram (C7).
   - `PreImportacao` → status `pendente_revisao`; **não entra em cálculo** (C6).
   - **Descarte** → após o job, arquivo temporário inexistente e **nenhuma** persistência de
     texto/PDF/sensível, **inclusive no caminho de erro** (C9, regra 6).
2. **Contrato/integração (borda):**
   - `ImportarFaturaJob` (fila fake) — fluxo feliz texto nativo (C3); fluxo OCR com
     `ExtratorDeTexto`/`OcrFallback` **fakados** (sem binário Tesseract no teste) (C4).
   - **Confirmação parcial** → só o subconjunto vira `transactions` com `origem='pdf'`;
     `installments` geradas pelo motor do Bloco 1; `audit_log` registrado (C8, regra 7).
   - `pdf_parse_errors` → trecho irreconhecível gera registro **sem dado sensível** e não
     aborta os itens válidos (C10).
   - Use os **fakes da Laravel AI SDK** caso a sugestão de categoria por IA seja exercida.

> Cada item de backend só é "feito" com **testes verdes e cobertura**. As **fixtures de
> texto** vivem no repositório; **nenhum PDF real** de fatura é commitado.

## 8. Backend agora · Frontend depois
| Backend (esta etapa) | Frontend (etapa separada e posterior) |
|---|---|
| Pipeline efêmero no worker (validação, extração, OCR, parser, pré-importação, efetivação, descarte) | **Tela web de revisão em lote** (marcar/desmarcar itens, ver duplicados) |
| Deduplicação por hash do nome + bloqueio de PDF com senha | Mensagem do bot: **resumo** dos lançamentos + pedido de confirmação total/parcial/cancela |
| Detecção de duplicidade/conciliação + registro em `pdf_parse_errors` | Avisos amigáveis (PDF com senha, item ignorado, "já importado") |
| Contratos `EfetivarImportacao`/`PreImportacao` + status em `invoice_imports` | Upload do arquivo (web) e recepção do documento (Telegram) |

## 9. Definition of Done
- [ ] Cenários C1–C10 cobertos por testes que falhavam antes e agora passam.
- [ ] Barreiras de §4 garantidas, **com teste dedicado** para: não retenção/descarte
      (regra 6), parsing determinístico de números (regra 4/5) e confirmação (regra 7).
- [ ] Sem segredo/PDF/dado sensível persistido, logado ou commitado (só fixtures de texto).
- [ ] Dependências adicionadas **via contêiner** (`make composer require ...`): lib de PDF
      e wrapper de OCR; **Tesseract (pt)** já embutido no `worker` (spec 00).
- [ ] Commit local atômico, em português, separando **backend** de **frontend** (regra 3).
- [ ] §10 preenchida com os artefatos reais e as decisões de regra tomadas.

## 10. Estado atual / artefatos
- **Status:** 🟡 **Pipeline completo e testado**; falta só a regra de extração do
  `ParserItau` (a identificação dos lançamentos da fatura), adiada para **depois do
  frontend**. Tudo o mais — leitura, OCR, dedupe, pré-importação, efetivação, notificação
  e descarte — está pronto e verde (486 testes na suíte).
- **Entregue:**
  - **Reuso estendido:** `App\Domain\Gasto\DadosGastoManual` ganhou `origem` (default
    `manual`); `RegistrarGastoManual` grava a origem informada (`pdf` na importação).
  - **Schema:** `database/migrations/2026_06_28_000001_create_invoice_imports_table.php`
    (metadados; índice `(user_id, hash_arquivo_nome)`; CHECK de status próprio) e
    `2026_06_28_000002_create_banks_and_pdf_parse_errors_tables.php` (`banks`,
    `pdf_parse_errors` + junção N:N `bank_pdf_parse_error`). Models `App\Models\InvoiceImport`,
    `Bank`, `PdfParseError`. Seeder `BankSeeder` (Itaú) registrado no `DatabaseSeeder`.
  - **Domínio `app/Domain/Importacao/`:** VOs `TextoExtraido`, `LancamentoExtraido`,
    `ItemPreImportacao`, `PreImportacao`, `ResultadoValidacao`; contrato `ParserDeFatura`
    (interface) + `ParserItau` (**stub** que lança `ParserNaoImplementadoException`);
    serviços `ValidadorDeArquivo`, `ExtratorDeTexto`/`ExtratorDeTextoPoppler`,
    `OcrFallback`/`OcrTesseract`, `DetectorDeDuplicidadeNaImportacao`,
    `MontadorDePreImportacao`, `RegistradorDeErroDeParsing`, `EfetivarImportacao`.
  - **Job/notificação:** `app/Jobs/ImportarFaturaJob.php` (fila/worker; `finally` apaga o
    temporário inclusive em erro). Notificação reusa a porta `RespostaAoUsuario` com os
    novos `TipoDeInteracao` IMPORTACAO_PRONTA / IMPORTACAO_PROTEGIDA_POR_SENHA /
    IMPORTACAO_FALHOU (carregando a `PreImportacao`); redação/envio é frontend.
  - **Bindings:** `AppServiceProvider` liga `ExtratorDeTexto`→Poppler, `OcrFallback`→Tesseract,
    `ParserDeFatura`→`ParserItau`.
  - **Testes:** `tests/Feature/Importacao/ImportarFaturaJobTest.php` (C2/C3/C4/C9/C10 com
    parser fake), `tests/Feature/Domain/Importacao/*` (Validador, Dedupe, Montador, Efetivar,
    RegistradorDeErro), `tests/Unit/Domain/Importacao/*` (VOs, stub do parser),
    `tests/Feature/Domain/{InvoiceImport,PdfParseError}Test.php`.
- **Sem dependências novas:** extração/OCR usam os binários `poppler-utils` + `tesseract`
  (pt) já embutidos no `worker` (spec 00), envolvidos por `Symfony\Process` atrás das
  interfaces — nada adicionado ao `composer.json`.
- **Pendente (única peça):** a **regra do `ParserItau`** — identificar descrição/valor
  (centavos)/data/parcelas no texto da fatura do Itaú, ignorando dado sensível (regras
  4/5/6), e registrar trechos irreconhecíveis em `pdf_parse_errors` **sem abortar** os itens
  válidos (C10, parte fina). Entra em `app/Domain/Importacao/ParserItau.php`; os testes de
  fixture de texto de §7.1 são escritos junto dela.
- **Adiado para frontend:** tela de revisão em lote, resumo/confirmação no bot, **upload web
  e recepção do documento via Telegram** (quem grava o temporário e despacha o
  `ImportarFaturaJob`), e as mensagens amigáveis dos novos `TipoDeInteracao`.
- **Decisões de regra tomadas:**
  - **Sem tabela `invoices`** (não existe no schema): itens efetivados ligam por `card_id` e
    a fatura é **derivada** pela `CalculadoraDeVencimento`, como o gasto manual de cartão.
  - **Dedupe (C1) é aviso, não bloqueio:** `(user_id, hash_arquivo_nome)` é **índice**, não
    UNIQUE — o usuário pode reprocessar mediante confirmação explícita.
  - **PDF com senha (C2):** não cria linha em `invoice_imports` ("nada é persistido") — só
    notifica para reenviar sem senha.
  - **Erro de parsing:** registra **apenas a classe do erro** em `pdf_parse_errors` (nunca o
    trecho do PDF) e marca a importação como `erro`.
