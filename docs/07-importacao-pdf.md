# 07 · Importação de PDF da fatura

> Referência: seção 8 do Escopo Final.

Funcionalidade de **alto valor e alto risco**. O PDF chega por Telegram ou web. Banco inicial suportado: **Itaú**, com **pipeline genérico extensível** para outros bancos.

Princípio inegociável: **o PDF e o texto extraído NUNCA são persistidos** — o processamento é efêmero e descartado ao final. Nenhum dado sensível é armazenado.

---

## 1. Pipeline (passo a passo)

1. **Recebe o PDF** (Telegram/web) e **valida o nome do arquivo** contra importações anteriores. Se for igual a uma importação já feita, pergunta se o usuário deseja prosseguir.
2. **PDF com senha** — por **LGPD, NÃO solicitar a senha**. Responde que um PDF protegido não pode ser processado e pede uma **versão sem senha**.
3. **Extrai a camada de texto.** Se não houver texto selecionável, usa **OCR (Tesseract) como fallback** para transformar o conteúdo em texto.
4. **Extrai os campos** — descrição, valor, data, parcelas — **ignorando TODO dado sensível** (nome, endereço, CPF, data de nascimento).
5. **Monta a PRÉ-IMPORTAÇÃO** — estado temporário; **nada é salvo efetivamente sem aceite** do usuário.
6. **Detecta duplicidade** por **valor + descrição + data + quantidade de parcelas** — **NUNCA pela parcela atual**.
7. **Usuário revisa** (tela em lote na web; resumo em texto no Telegram) e **confirma total ou parcialmente, ou cancela**.
8. **Sistema efetiva** — cria/atualiza lançamentos, vincula à fatura, registra auditoria e **DESCARTA o PDF e o texto**.

---

## 2. Regras de duplicidade e conciliação

- **Validação primária pelo nome do arquivo** do PDF.
- Nenhum lançamento salvo pode já existir com **mesmo valor, descrição, data e quantidade de parcelas**.
- A **parcela atual nunca entra** no critério de duplicidade (ela é sempre calculada na exibição).
- Itens ignorados por duplicidade são **informados ao usuário**.
- **Conciliação** com gastos já cadastrados via Telegram: o sistema informa quais são duplicados e pede ação.

---

## 3. Tratamentos e limites

| Tema | Decisão |
| --- | --- |
| **PDF original** | **Nunca armazenado** (descartado sempre). |
| **Texto extraído** | **Não retido** após o processamento. |
| **Vetorização da fatura** | **Removida do escopo** (conflita com a política de não retenção). |
| **Dados sensíveis** | **Ignorados integralmente**, nunca persistidos. |
| **Juros, IOF, multas, encargos, internacional** | **Fora do MVP** (modelo preparado para pós-MVP). |
| **Erros de leitura** | Registrados em **`pdf_parse_errors`** (tabela de bancos + N:N) para evoluir o parser. |

---

## 4. Notas de implementação

- A pré-importação fica com status `pendente_revisao` — **nunca entra em cálculo até a confirmação** (ver seção 4.4).
- Metadados de controle vivem em `invoice_imports` (`hash_arquivo_nome`, `status`...); **o PDF não é salvo**, só esses metadados.
- A política de **não retenção** é o que esvazia a necessidade de RAG documental (ver seção 16).
- Todo o processamento roda no **worker**, via fila, fora do request.
