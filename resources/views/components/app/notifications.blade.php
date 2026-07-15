@props([
    // Notificações já prontas do backend. Cada item é um array associativo:
    //   ['icone' => 'receipt', 'tom' => 'ocre', 'titulo' => '…',
    //    'descricao' => '…', 'quando' => '09:42', 'lida' => false]
    // A UI só apresenta: valores (R$, datas) chegam formatados; nada é
    // calculado aqui (regras 4 e 5).
    //
    // Notificações são pós-MVP (docs/specs/06-dashboard.md). Sem fonte real,
    // o padrão é o estado VAZIO ("Tudo em dia") — nunca dados de demonstração:
    // valores financeiros inventados no header quebrariam a confiança do
    // usuário (auditoria P1-4). Ligue à fonte real quando houver.
    'notificacoes' => [],
])

@php
    $itens = collect($notificacoes ?? []);
    $naoVistas = $itens->reject(fn ($n) => $n['lida'] ?? false)->values();
    $vistas = $itens->filter(fn ($n) => $n['lida'] ?? false)->values();
    $qtdNaoVistas = $naoVistas->count();

    // Tom do item → cor do ícone. Só tokens do design system (sem hex solto).
    $tons = [
        'ocre' => 'bg-ocre/10 text-ocre',
        'cedula' => 'bg-cedula/10 text-cedula',
        'primary' => 'bg-primary-fixed/40 text-primary',
        'neutro' => 'bg-surface-container-high text-on-surface-variant',
    ];
@endphp

<div class="relative shrink-0">
    {{-- Sino + selo de não lidas. É o único acréscimo ao header (o resto do
         header e da barra lateral permanece intocado). --}}
    <button type="button" id="notif-trigger"
        aria-haspopup="true" aria-expanded="false" aria-controls="notif-panel"
        class="-mr-1 rounded-full p-2 text-on-surface-variant transition-colors hover:bg-surface-container-high hover:text-primary active:scale-95">
        <x-icon name="bell" class="h-5 w-5" />
        <span class="sr-only">{{ $qtdNaoVistas > 0 ? "Notificações — {$qtdNaoVistas} não lidas" : 'Notificações' }}</span>
    </button>

    @if ($qtdNaoVistas > 0)
        <span id="notif-badge"
            class="pointer-events-none absolute right-0.5 top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-cedula px-1 font-mono text-[10px] font-semibold leading-none text-white ring-2 ring-superficie">
            {{ $qtdNaoVistas > 9 ? '9+' : $qtdNaoVistas }}
        </span>
    @endif

    {{-- Painel suspenso. Estados: com itens ("Aberto") ou vazio ("Tudo em dia").
         Ancorado à direita; largura limitada para não estourar em telas ≤ 360px. --}}
    <div id="notif-panel" role="region" aria-label="Notificações" hidden
        class="notebook-card absolute right-0 z-50 mt-2 flex w-[min(360px,calc(100vw-2.5rem))] origin-top-right scale-95 flex-col overflow-hidden rounded-card opacity-0 shadow-xl transition duration-150 ease-out">
        {{-- Cabeçalho do painel --}}
        <div class="flex items-center justify-between border-b border-linha px-4 py-4">
            <h2 class="font-headline-md text-headline-md text-on-surface">Notificações</h2>
            @if ($qtdNaoVistas > 0)
                <button type="button" id="notif-mark-all"
                    class="font-body-sm text-label-sm font-semibold text-primary transition-colors hover:underline">
                    Marcar todas como lidas
                </button>
            @endif
        </div>

        @if ($itens->isEmpty())
            {{-- ---------------------------------------------------------------
                 Estado vazio — "Tudo em dia"
            ---------------------------------------------------------------- --}}
            <div class="flex flex-col items-center justify-center px-8 py-14 text-center">
                <div class="relative mb-6">
                    <div class="flex h-20 w-20 items-center justify-center rounded-full bg-surface-container-low text-outline-variant">
                        <x-icon name="mail-open" class="h-9 w-9" />
                    </div>
                    <span class="absolute -bottom-1 -right-1 flex h-6 w-6 rotate-12 items-center justify-center rounded border border-linha bg-superficie text-primary shadow-sm">
                        <x-icon name="check" class="h-3.5 w-3.5" />
                    </span>
                </div>
                <h3 class="mb-1 font-body-md text-body-md font-medium text-on-surface">Tudo em dia</h3>
                <p class="max-w-[240px] font-body-sm text-body-sm text-on-surface-variant">
                    Nenhuma notificação por aqui no momento.
                </p>
            </div>
        @else
            {{-- ---------------------------------------------------------------
                 Estado com itens — grupos "Não vistas" e "Vistas"
            ---------------------------------------------------------------- --}}
            <div class="max-h-[70vh] overflow-y-auto md:max-h-[480px]">
                @if ($naoVistas->isNotEmpty())
                    <div class="py-2" data-notif-group>
                        <p class="px-4 py-1 font-label-sm text-label-sm font-semibold uppercase tracking-wider text-on-surface-variant opacity-60">Não vistas</p>
                        @foreach ($naoVistas as $i => $n)
                            @if ($i > 0)
                                <div class="mx-4 h-px bg-linha"></div>
                            @endif
                            <button type="button" data-notif-item
                                class="notif-item relative flex w-full items-start gap-4 bg-cedula/5 px-4 py-3 text-left transition-colors"
                                aria-label="Marcar como lida: {{ $n['titulo'] }}">
                                <span class="notif-dot absolute left-1 top-1/2 h-2 w-2 -translate-y-1/2 rounded-full bg-cedula" aria-hidden="true"></span>
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-control {{ $tons[$n['tom']] ?? $tons['neutro'] }}">
                                    <x-icon :name="$n['icone']" class="h-5 w-5" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="font-body-sm text-body-sm font-semibold text-on-surface">{{ $n['titulo'] }}</p>
                                        <span class="shrink-0 font-mono text-[11px] text-on-surface-variant">{{ $n['quando'] }}</span>
                                    </div>
                                    <p class="truncate font-body-sm text-body-sm text-on-surface-variant">{{ $n['descricao'] }}</p>
                                </div>
                            </button>
                        @endforeach
                    </div>
                @endif

                @if ($vistas->isNotEmpty())
                    <div class="py-2">
                        <p class="px-4 py-1 font-label-sm text-label-sm font-semibold uppercase tracking-wider text-on-surface-variant opacity-60">Vistas</p>
                        @foreach ($vistas as $i => $n)
                            @if ($i > 0)
                                <div class="mx-4 h-px bg-linha"></div>
                            @endif
                            <div class="flex items-start gap-4 px-4 py-3 transition-colors hover:bg-surface-container">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-control bg-surface-container-high text-on-surface-variant">
                                    <x-icon :name="$n['icone']" class="h-5 w-5" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="font-body-sm text-body-sm text-on-surface">{{ $n['titulo'] }}</p>
                                        <span class="shrink-0 font-mono text-[11px] text-on-surface-variant opacity-60">{{ $n['quando'] }}</span>
                                    </div>
                                    <p class="truncate font-body-sm text-body-sm text-on-surface-variant/70">{{ $n['descricao'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Rodapé — "Ver todas". A lista dedicada é pós-MVP; por ora é discreta
             (em breve), sem destino falso. --}}
        <div class="border-t border-linha bg-surface-container-lowest/50 px-4 py-4 text-center">
            <button type="button" aria-disabled="true" title="Em breve"
                class="inline-flex cursor-default items-center justify-center gap-1 font-body-sm text-body-sm font-medium text-outline/60">
                Ver todas
                <x-icon name="chevron-right" class="h-4 w-4" />
            </button>
        </div>
    </div>
</div>

{{-- O comportamento (abrir/fechar/marcar lida) vive em resources/js/shell/notifications.js
     (importado pelo app.js): script inline aqui era bloqueado pela CSP estrita de
     produção — script-src 'self', sem 'unsafe-inline' (P3-13). --}}
