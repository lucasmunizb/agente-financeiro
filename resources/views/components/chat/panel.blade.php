{{-- Chat financeiro — coluna fixa (spec FE §7.14). A TERCEIRA zona do shell:
     nav à esquerda · conteúdo no centro · este chat à direita. No desktop (lg+)
     está SEMPRE ABERTA e faz parte do layout (sem backdrop, sem botão fechar);
     abaixo de lg recolhe para uma folha que desliza da direita, aberta pelo
     lançador do header. O posicionamento/colapso é do layout (x-layouts.app);
     aqui mora só o conteúdo da coluna.

     Histórico REAL e isolado por usuário (chat_messages), injetado pelo view
     composer (`$mensagens`). O envio conversa com o MESMO motor do Telegram
     (ResponderConsulta) via /chat/mensagens; o chat.js cuida da interação. A UI
     NUNCA calcula dinheiro (regra 4): os valores vêm prontos e validados pelo
     guard. O anexo é SOMENTE PDF e efêmero (regra 6): validado no cliente e no
     servidor (MIME real), nunca armazenado. --}}
@php
    $mensagens ??= collect();
@endphp

<aside id="chat-panel"
    data-store-url="{{ route('chat.store') }}"
    data-index-url="{{ route('chat.index') }}"
    data-csrf="{{ csrf_token() }}"
    class="fixed inset-y-0 right-0 z-50 flex w-full max-w-[380px] translate-x-full flex-col bg-superficie shadow-xl transition-transform duration-200 ease-out lg:z-30 lg:translate-x-0 lg:border-l lg:border-linha lg:shadow-none"
    aria-label="Chat financeiro">

    {{-- 1. Cabeçalho da coluna. O "fechar" só existe na folha do mobile; no
         desktop a coluna é permanente (spec: sem botão fechar). --}}
    <header class="flex h-16 shrink-0 items-center justify-between border-b border-linha bg-superficie px-6">
        <h2 class="font-headline-md text-headline-md text-on-surface">Chat financeiro</h2>
        <button type="button" id="chat-close"
            class="-mr-1.5 rounded-full p-1.5 text-on-surface-variant transition-colors hover:bg-surface-container lg:hidden">
            <x-icon name="x" class="h-5 w-5" />
            <span class="sr-only">Fechar chat</span>
        </button>
    </header>

    {{-- 2. Banner de transparência de IA (barreira 5). --}}
    <div class="flex shrink-0 items-start gap-3 border-b border-linha bg-primary-container/5 px-6 py-3">
        <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-primary-container" />
        <p class="font-label-sm text-label-sm leading-snug text-on-surface-variant">
            Respostas geradas com IA. Os números vêm do seu banco de dados — a IA nunca os inventa.
        </p>
    </div>

    {{-- 3. Histórico (área rolável — ocupa a maior parte da altura). --}}
    <div id="chat-history" class="flex flex-1 flex-col gap-6 overflow-y-auto px-6 py-6">
        {{-- Estado vazio: some assim que existir a primeira mensagem (JS o esconde). --}}
        <div id="chat-empty" @class(['flex flex-1 flex-col items-center justify-center gap-3 text-center', 'hidden' => $mensagens->isNotEmpty()])>
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-surface-container text-outline">
                <x-icon name="message-circle" class="h-6 w-6" />
            </span>
            <p class="max-w-[260px] font-body-md text-body-md text-on-surface-variant">
                Registre um gasto (ex.: “gastei 50 no mercado”), pergunte sobre suas finanças ou anexe a fatura em PDF.
            </p>
        </div>

        @foreach ($mensagens as $m)
            <x-chat.message :role="$m->role" :body="$m->body" :tem-anexo="$m->tem_anexo" />
        @endforeach
    </div>

    {{-- 4. Área de entrada fixa no rodapé. O anexo aceita SOMENTE PDF. --}}
    <div class="shrink-0 border-t border-linha bg-superficie p-6">
        {{-- Anexo escolhido (pronto para enviar) — revelado pelo JS. Efêmero. --}}
        <div id="chat-attachment" hidden
            class="mb-3 flex items-center gap-2 rounded-control border border-linha bg-surface-container-low px-3 py-2">
            <x-icon name="file-text" class="h-4 w-4 shrink-0 text-on-surface-variant" />
            <span id="chat-filename" class="min-w-0 flex-1 truncate font-label-sm text-label-sm text-on-surface"></span>
            <span class="shrink-0 font-label-sm text-label-sm text-nevoa">· PDF</span>
            <button type="button" id="chat-remove"
                class="ml-1 shrink-0 rounded-full p-1 text-on-surface-variant transition-colors hover:bg-surface-container">
                <x-icon name="x" class="h-3.5 w-3.5" />
                <span class="sr-only">Remover anexo</span>
            </button>
        </div>
        <p id="chat-attach-note" hidden class="mb-3 font-label-sm text-label-sm text-nevoa">
            O PDF é processado e descartado — nada fica armazenado.
        </p>

        {{-- Anexo inválido (não-PDF) — revelado pelo JS. --}}
        <p id="chat-invalid" hidden class="mb-2 font-label-sm text-label-sm text-argila">Aceito apenas PDF.</p>

        <div class="flex flex-col gap-3">
            <div>
                <button type="button" id="chat-attach"
                    class="inline-flex min-h-[44px] items-center gap-2 rounded-control px-2 py-2 text-on-surface-variant transition-colors hover:text-on-surface">
                    <x-icon name="paperclip" class="h-5 w-5" />
                    <span class="font-label-sm text-label-sm">Anexar PDF</span>
                </button>
                <input type="file" id="chat-file" accept="application/pdf,.pdf" class="hidden">
            </div>

            <div class="flex items-end gap-2 rounded-control border border-linha bg-superficie transition-colors focus-within:border-primary-container">
                <label for="chat-input" class="sr-only">Registre um gasto ou pergunte sobre suas finanças</label>
                <textarea id="chat-input" rows="1" placeholder="Registre um gasto ou pergunte…"
                    class="max-h-32 min-h-[44px] flex-1 resize-none bg-transparent px-4 py-3 font-body-md text-body-md text-on-surface placeholder:text-outline focus:outline-none"></textarea>
                <button type="button" id="chat-send" disabled
                    class="m-1 flex h-11 w-11 shrink-0 items-center justify-center rounded-control bg-primary-container text-on-primary transition-colors hover:bg-cedula-clara disabled:cursor-not-allowed disabled:opacity-40">
                    <x-icon name="arrow-up" class="h-5 w-5" />
                    <span class="sr-only">Enviar</span>
                </button>
            </div>
        </div>
    </div>
</aside>
