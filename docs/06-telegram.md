# 06 · Integração com Telegram

> Referência: seção 7 do Escopo Final.

O Telegram é o primeiro canal conversacional do produto. O desenho mantém o canal separado da regra de negócio (adaptador fino), o que permite extrair o bot para um serviço próprio e suportar WhatsApp no futuro sem tocar no domínio.

---

## 1. Vínculo e autenticação

### Correção técnica importante

A ideia original previa vincular o **MAC do dispositivo**. Isso **não é viável**: a Bot API do Telegram **NÃO expõe MAC nem qualquer identificador de hardware**. A API entrega apenas:

- `telegram_user_id` — imutável e único por usuário;
- `chat_id` — identificador da conversa.

Portanto, o vínculo seguro usa **`telegram_user_id` + telefone verificado**, **nunca MAC**.

### Esquema de vínculo seguro (passo a passo)

1. **Web gera o token** — o usuário cria a conta na web e gera um **token único, aleatório e com expiração curta**.
2. **Usuário envia o token ao bot** — em uma mensagem específica no Telegram.
3. **Bot valida e captura identidade** — o bot valida o token, captura o `telegram_user_id` e solicita o telefone via **`request_contact`** (compartilhamento nativo do Telegram).
4. **Sistema vincula e consome** — vincula `telegram_user_id` + telefone à conta e marca o **token como consumido**.

### Regras do vínculo

- **Apenas um vínculo ativo por conta.**
- Comandos só são aceitos **após vínculo válido**.
- **Middleware de autenticação em TODA mensagem** — nenhuma mensagem é processada sem usuário identificado.

> Persistência: tabela `telegram_links` (`user_id`, `telegram_user_id`, `telefone`, `token`, `status`, `vinculado_em`) — ver Modelo de Dados (seção 5).

---

## 2. Comportamento do bot

| Aspecto | Decisão |
| --- | --- |
| **Comandos básicos** | Registrar, editar, cancelar, buscar. |
| **Entrada** | Texto livre **e** comandos estruturados (ambos). |
| **Anexos** | **Somente PDF.** |
| **Confirmação** | Sempre confirma após interpretar; **persiste só após o aceite**. |
| **Botões** | **Sem botões no bot.** Bot responde curto; confirmação/edição detalhada é feita na web. |
| **Consultas** | Saldo, gastos do mês, próximas contas, faturas. |
| **Preferências** | **Não** configuráveis pelo Telegram. |
| **Duplicidade por instabilidade** | Mantém a **primeira** mensagem (dedupe por `update_id`). |
| **Timeout de IA/PDF** | Responde "instabilidade, tentando novamente" e **reprocessa** (re-enfileira). |
| **Lembretes / alertas** | **Fora do MVP**; entram depois. |

---

## 3. Notas de implementação

- O webhook chega no próprio app (sem contêiner extra no MVP); o adaptador Telegram é isolado no código.
- **Registro do webhook (por bot, do lado do Telegram):** o webhook **não** é setado no deploy — registra-se uma vez por ambiente com `php artisan telegram:webhook <url>`. Ele **persiste** entre deploys (não some num redeploy normal). Em produção, rodar de dentro de um container do app garante que o `secret_token` casa com o secret `telegram_webhook_secret`. Inspecionar/remover: `telegram:webhook --info` / `--delete`.
- **Use um bot SEPARADO em dev.** O webhook é único por bot. Se dev e produção usam o mesmo token, apontar o webhook para o túnel de dev (`telegram:webhook https://<túnel>/telegram/webhook`) **rouba** o webhook da produção — o bot de prod fica mudo até re-registrar a URL real. Bot de dev com token próprio (ver `.env.example`) elimina o conflito.
- A deduplicação por `update_id` usa **unique constraint** no banco — garante idempotência sem Redis.
- A IA atua só nos três papéis definidos (intenção, extração, redação); **nunca calcula dinheiro** — ver Governança de IA (seção 3).
- Processamento pesado (PDF, chamadas de IA) roda no **worker** via fila, fora do request.
