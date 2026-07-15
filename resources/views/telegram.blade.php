@php
    // Estado e dados vêm do TelegramLinkController (backend). Defaults defensivos
    // para o caso de a view ser renderizada isolada.
    $estado ??= 'pendente';
    $expirado = $estado === 'expirado';
    $vinculado = $estado === 'vinculado';

    $token ??= '';
    $botUsername ??= 'AgenteFinanceiroBot';
    $deepLink ??= 'https://t.me/'.$botUsername.'?start='.$token;
    $expiraEmSegundos ??= 0;
    $handle ??= 'Conta conectada';

    $heading = $vinculado ? 'Telegram conectado' : 'Conectar o Telegram';
    $subheading = $vinculado
        ? 'Você já pode registrar e consultar gastos direto no chat.'
        : 'Registre e consulte gastos direto no chat, de forma simples.';
@endphp

<x-layouts.app :title="$heading.' | Agente Financeiro'" active="telegram"
    :heading="$heading" :subheading="$subheading">
    @push('scripts')
        @vite('resources/js/pages/telegram.js')
    @endpush

    @if ($vinculado)
        {{-- ---------------------------------------------------------------
             Estado: vinculado (conexão ativa)
        ---------------------------------------------------------------- --}}
        <section class="notebook-card w-full max-w-md rounded-xl p-8 md:p-10 flex flex-col items-center text-center">
            <div class="mb-6 flex h-20 w-20 items-center justify-center rounded-2xl bg-primary-fixed text-primary shadow-sm">
                <x-icon name="send" class="h-9 w-9" />
            </div>

            <span class="mb-4 inline-flex items-center gap-1.5 rounded-full border border-primary-container/20 bg-primary-container/10 px-3 py-1 font-label-sm text-label-sm text-primary-container">
                <x-icon name="check" class="h-4 w-4" />
                Conectado
            </span>

            <div class="mb-8 flex w-full flex-col items-center gap-1 rounded-lg bg-primary-container/5 py-4 px-6">
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Identificador vinculado</span>
                <span class="font-value-display text-value-display text-primary">{{ $handle }}</span>
            </div>

            <ul class="w-full space-y-4 text-left">
                <li class="flex items-start gap-3">
                    <x-icon name="shield-check" class="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                    <p class="font-body-sm text-body-sm text-on-surface-variant">Receba alertas dos seus gastos direto no Telegram.</p>
                </li>
                <li class="flex items-start gap-3">
                    <x-icon name="bar-chart" class="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                    <p class="font-body-sm text-body-sm text-on-surface-variant">Consulte seu saldo com comandos simples, como <strong class="font-mono text-on-surface">/saldo</strong>.</p>
                </li>
            </ul>

            {{-- Ação destrutiva: desvincular. Confirmação em dois passos SEM depender de
                 JS (regra 7 na borda — auditoria P3-5): o <details> revela o aviso e só
                 então o POST real fica disponível. --}}
            <div class="mt-10 w-full border-t border-linha pt-6">
                <details class="group">
                    <summary class="flex w-full cursor-pointer list-none items-center justify-center gap-2 rounded-lg border border-argila py-3 px-4 font-body-md text-body-md font-semibold text-argila transition-colors hover:bg-argila/10 active:scale-[0.98] [&::-webkit-details-marker]:hidden">
                        <x-icon name="unlink" class="h-5 w-5" />
                        Desconectar Telegram
                    </summary>
                    <div class="mt-4 space-y-4 rounded-lg border border-argila/30 bg-argila/5 p-4 text-left">
                        <p class="font-body-sm text-body-sm text-on-surface-variant">
                            Desconectar o Telegram? Você deixará de registrar gastos pelo chat.
                        </p>
                        <form method="POST" action="{{ route('telegram.desconectar') }}">
                            @csrf
                            <button type="submit"
                                class="flex w-full items-center justify-center gap-2 rounded-lg bg-argila py-3 px-4 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-argila/90 active:scale-[0.98]">
                                Sim, desconectar
                            </button>
                        </form>
                    </div>
                </details>
            </div>
        </section>
    @else
        {{-- ---------------------------------------------------------------
             Estados: pendente / expirado (fluxo de dois passos)
             data-poll-status: a tela consulta o status enquanto aguarda a
             confirmação no bot e recarrega ao vincular (mostra "conectado").
        ---------------------------------------------------------------- --}}
        <section class="notebook-card w-full max-w-md rounded-xl p-8 md:p-10"
            data-poll-status="{{ route('telegram.status') }}">
            <ol class="space-y-12">
                {{-- Passo 1 — abrir o bot --}}
                <li class="flex items-start gap-6">
                    <span class="step-number shrink-0" aria-hidden="true">1</span>
                    <div class="w-full space-y-4">
                        <h2 class="font-headline-md text-headline-md text-on-surface">Abra o bot</h2>
                        <a href="{{ $deepLink }}" target="_blank" rel="noopener"
                            class="flex w-full items-center justify-center gap-2 rounded-lg bg-cedula py-4 font-body-md text-body-md font-semibold text-superficie shadow-sm transition-colors hover:bg-cedula-clara active:scale-[0.98]">
                            <x-icon name="send" class="h-5 w-5" />
                            Abrir no Telegram
                        </a>
                    </div>
                </li>

                <li class="h-px bg-linha mx-4" aria-hidden="true"></li>

                {{-- Passo 2 — confirmar o telefone com o código --}}
                <li class="flex items-start gap-6">
                    <span class="step-number shrink-0" aria-hidden="true">2</span>
                    <div class="w-full space-y-6">
                        <div class="space-y-2">
                            <h2 class="font-headline-md text-headline-md text-on-surface">Confirme o telefone</h2>
                            <p class="font-body-sm text-body-sm leading-relaxed text-on-surface-variant">
                                O bot vai pedir para compartilhar seu contato. É assim que confirmamos que o número é seu.
                            </p>
                        </div>

                        {{-- Código de uso único --}}
                        <div class="space-y-3">
                            <p class="text-center font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Código de uso único</p>

                            <div @class([
                                'token-display relative flex items-center gap-3 overflow-hidden px-6 py-3',
                                'select-none' => $expirado,
                            ])>
                                @if ($expirado)
                                    <div class="absolute inset-0 z-10 flex items-center justify-center bg-superficie/60 backdrop-blur-[1px]">
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-error-container px-3 py-1 font-label-sm text-label-sm text-on-error-container shadow-sm">
                                            <x-icon name="alert" class="h-4 w-4" />
                                            Este código expirou
                                        </span>
                                    </div>
                                @endif
                                {{-- Código completo, em mono menor que quebra em linha
                                     (break-all) para caber no card sem estourar a div. --}}
                                <span @class([
                                    'min-w-0 flex-1 break-all text-center font-mono text-body-md leading-relaxed tracking-wider',
                                    'text-outline' => $expirado,
                                    'text-primary' => ! $expirado,
                                ]) data-token="{{ $token }}">{{ $token }}</span>
                                @unless ($expirado)
                                    <button type="button" data-copy-token
                                        class="shrink-0 cursor-pointer text-outline transition-colors hover:text-primary"
                                        aria-label="Copiar código">
                                        <x-icon name="copy" class="h-5 w-5" />
                                    </button>
                                @endunless
                            </div>

                            @unless ($expirado)
                                <div class="flex items-center justify-center gap-2 pt-1">
                                    <x-icon name="clock" class="h-4 w-4 text-ocre" />
                                    <span class="font-value-label text-value-label text-on-surface-variant"
                                        data-countdown data-seconds="{{ $expiraEmSegundos }}">expira em --:--</span>
                                </div>
                            @endunless
                        </div>

                        {{-- Gerar novo código: primário quando expirado; secundário
                             quando ainda válido. O POST emite outro token e revoga o anterior. --}}
                        <form method="POST" action="{{ route('telegram.gerar') }}">
                            @csrf
                            <button type="submit" @class([
                                'flex w-full items-center justify-center gap-2 rounded-lg py-3.5 font-body-md text-body-md font-semibold transition-colors active:scale-[0.98]',
                                'bg-cedula text-superficie shadow-sm hover:bg-cedula-clara' => $expirado,
                                'border border-cedula text-cedula hover:bg-cedula/5' => ! $expirado,
                            ])>
                                <x-icon name="refresh-cw" class="h-5 w-5" />
                                Gerar novo código
                            </button>
                        </form>
                    </div>
                </li>
            </ol>
        </section>
    @endif

    {{-- Rodapé de ajuda --}}
    <p class="mt-8 text-center font-body-sm text-body-sm text-outline">
        Dúvidas? Veja o <a href="{{ route('terms') }}" class="font-medium text-primary underline underline-offset-2 hover:text-cedula-clara">guia de uso</a>.
    </p>
</x-layouts.app>
