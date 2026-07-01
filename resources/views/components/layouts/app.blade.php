@props([
    'title' => 'Agente Financeiro',
    'heading' => null,
    'subheading' => null,
    'active' => null, // chave do item de navegação ativo
])

@php
    // Navegação do app. As telas ainda não construídas apontam para '#' (marcadas
    // como "em breve"); Telegram já existe. A apresentação é etapa separada do
    // backend (regra 3) — estes destinos amadurecem conforme cada tela nasce.
    $nav = [
        ['key' => 'dashboard', 'label' => 'Visão Geral', 'icon' => 'layout-dashboard', 'href' => route('home'), 'soon' => false],
        ['key' => 'transacoes', 'label' => 'Transações', 'icon' => 'receipt', 'href' => '#', 'soon' => true],
        ['key' => 'relatorios', 'label' => 'Relatórios', 'icon' => 'bar-chart', 'href' => '#', 'soon' => true],
        ['key' => 'telegram', 'label' => 'Telegram', 'icon' => 'send', 'href' => route('telegram'), 'soon' => false],
    ];

    // Iniciais para o avatar (fallback "AF"). Nome nunca é exposto em meta/OG.
    $nome = trim((string) (auth()->user()?->name ?? ''));
    $iniciais = collect(preg_split('/\s+/', $nome, -1, PREG_SPLIT_NO_EMPTY))
        ->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('') ?: 'AF';
    $primeiroNome = $nome !== '' ? explode(' ', $nome)[0] : 'Minha conta';
@endphp

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>{{ $title }}</title>
    {{-- Tela autenticada: nunca indexar (privacidade + sem conteúdo público). --}}
    <meta name="robots" content="noindex, nofollow">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>

<body class="min-h-screen flex text-on-surface">
    <div class="paper-texture" aria-hidden="true"></div>

    {{-- Barra lateral (desktop) --}}
    <aside class="hidden md:flex w-64 shrink-0 flex-col h-screen sticky top-0 border-r border-linha bg-superficie">
        <div class="p-6">
            <span class="block font-headline-md text-headline-md text-primary tracking-tight">Agente Financeiro</span>
            <span class="mt-1 block font-label-sm text-label-sm text-outline uppercase tracking-widest">Gestão com clareza</span>
        </div>

        <nav class="flex-1 px-4 space-y-1" aria-label="Navegação principal">
            @foreach ($nav as $item)
                <a href="{{ $item['href'] }}"
                    @if ($active === $item['key']) aria-current="page" @endif
                    @if ($item['soon']) aria-disabled="true" title="Em breve" @endif
                    class="nav-item flex items-center gap-3 rounded-control px-4 py-3 font-body-md text-body-md transition-colors">
                    <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                    <span>{{ $item['label'] }}</span>
                    @if ($item['soon'])
                        <span class="ml-auto font-label-sm text-[10px] text-outline uppercase tracking-wide opacity-70">em breve</span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="p-4 border-t border-linha space-y-1">
            <a href="#" aria-disabled="true" title="Em breve"
                class="nav-item flex items-center gap-3 rounded-control px-4 py-3 font-body-md text-body-md transition-colors">
                <x-icon name="settings" class="h-5 w-5 shrink-0" />
                <span>Configurações</span>
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                    class="nav-item nav-item--danger flex w-full items-center gap-3 rounded-control px-4 py-3 font-body-md text-body-md transition-colors">
                    <x-icon name="log-out" class="h-5 w-5 shrink-0" />
                    <span>Sair</span>
                </button>
            </form>
        </div>
    </aside>

    {{-- Coluna de conteúdo --}}
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        {{-- Barra superior --}}
        <header class="sticky top-0 z-40 border-b border-linha bg-superficie/90 backdrop-blur">
            <div class="flex items-center justify-between px-container-padding-mobile md:px-container-padding-desktop py-4">
                <span class="font-headline-md text-headline-md text-primary md:hidden">Agente Financeiro</span>
                <div class="ml-auto flex items-center gap-2">
                    <button type="button" aria-disabled="true" title="Em breve"
                        class="relative rounded-full p-2 text-on-surface-variant transition-colors hover:bg-surface-container-low">
                        <x-icon name="bell" class="h-5 w-5" />
                        <span class="sr-only">Notificações</span>
                    </button>
                    <div class="mx-1 h-6 w-px bg-linha" aria-hidden="true"></div>
                    <div class="flex items-center gap-2 rounded-full p-1 pr-3">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-container font-label-sm text-label-sm font-semibold text-white">{{ $iniciais }}</span>
                        <span class="hidden font-body-sm text-body-sm text-on-surface-variant sm:inline">{{ $primeiroNome }}</span>
                    </div>
                </div>
            </div>
        </header>

        {{-- Canvas principal --}}
        <main class="flex-1 bg-surface-container-low pb-24 md:pb-0">
            <div class="mx-auto flex max-w-4xl flex-col items-center px-container-padding-mobile md:px-container-padding-desktop py-section-gap">
                @if ($heading)
                    <div class="mb-10 w-full max-w-2xl text-center">
                        <h1 class="font-headline-lg-mobile md:font-headline-lg text-headline-lg-mobile md:text-headline-lg text-on-surface mb-2">{{ $heading }}</h1>
                        @if ($subheading)
                            <p class="font-body-md text-body-md text-on-surface-variant">{{ $subheading }}</p>
                        @endif
                    </div>
                @endif

                {{ $slot }}
            </div>
        </main>
    </div>

    {{-- Navegação inferior (mobile) --}}
    <nav class="fixed bottom-0 left-0 z-50 flex h-16 w-full items-center justify-around border-t border-linha bg-superficie md:hidden"
        aria-label="Navegação principal">
        @foreach ($nav as $item)
            <a href="{{ $item['href'] }}"
                @if ($active === $item['key']) aria-current="page" @endif
                @class([
                    'flex flex-col items-center justify-center gap-0.5 px-3 py-1',
                    'text-primary' => $active === $item['key'],
                    'text-on-surface-variant' => $active !== $item['key'],
                ])>
                <x-icon :name="$item['icon']" class="h-5 w-5" />
                <span class="font-label-sm text-[10px]">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

    {{-- Toast (feedback efêmero) --}}
    <div id="toast" role="status" aria-live="polite"
        class="pointer-events-none fixed bottom-20 left-1/2 z-[100] -translate-x-1/2 translate-y-4 rounded-full bg-inverse-surface px-6 py-3 font-body-sm text-body-sm text-inverse-on-surface opacity-0 shadow-xl transition-all duration-300 md:bottom-10">
    </div>

    @stack('scripts')
</body>

</html>
