@props([
    'title' => 'Agente Financeiro',
    'heading' => null,
    'subheading' => null,
    'active' => null, // chave do item de navegação ativo
    'notificacoes' => null, // coleção pronta do backend; null → usa o demo do componente
    'wide' => false, // canvas largo, alinhado à esquerda (dashboard/listas); padrão é a coluna estreita centrada
])

@php
    // Navegação do app. As telas ainda não construídas apontam para '#' (marcadas
    // como "em breve"); Telegram já existe. A apresentação é etapa separada do
    // backend (regra 3) — estes destinos amadurecem conforme cada tela nasce.
    $nav = [
        ['key' => 'dashboard', 'label' => 'Visão Geral', 'icon' => 'layout-dashboard', 'href' => route('home'), 'soon' => false],
        ['key' => 'transacoes', 'label' => 'Transações', 'icon' => 'receipt', 'href' => route('lancamentos'), 'soon' => false],
        ['key' => 'relatorios', 'label' => 'Relatórios', 'icon' => 'bar-chart', 'href' => '#', 'soon' => true],
        ['key' => 'telegram', 'label' => 'Telegram', 'icon' => 'send', 'href' => route('telegram'), 'soon' => false],
        ['key' => 'configuracoes', 'label' => 'Configurações', 'icon' => 'settings', 'href' => '#', 'soon' => true],
    ];

    // Iniciais para o avatar (fallback "AF"). Nome nunca é exposto em meta/OG.
    $nome = trim((string) (auth()->user()?->name ?? ''));
    $iniciais = collect(preg_split('/\s+/', $nome, -1, PREG_SPLIT_NO_EMPTY))
        ->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('') ?: 'AF';
    $nomeExibicao = $nome !== '' ? $nome : 'Minha conta';
    $email = (string) (auth()->user()?->email ?? '');
@endphp

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>{{ $title }}</title>
    {{-- Tela autenticada: nunca indexar (privacidade + sem conteúdo público). --}}
    <meta name="robots" content="noindex, nofollow">

    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/pages/chat.js'])
    @stack('head')
</head>

<body class="min-h-screen overflow-x-clip text-on-surface">
    <div class="paper-texture" aria-hidden="true"></div>

    {{-- Backdrop do drawer (só mobile, quando aberto) --}}
    <div id="sidebar-backdrop" hidden
        class="fixed inset-0 z-40 bg-inverse-surface/40 backdrop-blur-sm md:hidden"></div>

    {{-- Barra lateral. Fixa no desktop; drawer off-canvas no mobile (abre pelo
         hambúrguer do header). Um só padrão de navegação em ambas as larguras. --}}
    <aside id="app-sidebar"
        class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col bg-surface-container-low shadow-sm transition-transform duration-200 ease-out md:translate-x-0"
        aria-label="Navegação principal">
        {{-- Marca --}}
        <div class="flex items-center justify-between px-6 pt-gutter pb-4">
            <span class="font-headline-md text-headline-md text-primary tracking-tight">Agente Financeiro</span>
            <button type="button" id="sidebar-close"
                class="rounded-full p-1.5 text-on-surface-variant transition-colors hover:bg-surface-container md:hidden">
                <x-icon name="x" class="h-5 w-5" />
                <span class="sr-only">Fechar menu</span>
            </button>
        </div>

        {{-- Perfil do usuário --}}
        <div class="flex items-center gap-3 px-6 pb-4">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-container font-label-sm text-label-sm font-semibold text-white">{{ $iniciais }}</span>
            <div class="min-w-0">
                <p class="truncate font-body-sm text-body-sm font-semibold text-on-surface">{{ $nomeExibicao }}</p>
                @if ($email !== '')
                    <p class="truncate font-label-sm text-label-sm text-outline">{{ $email }}</p>
                @endif
            </div>
        </div>

        <nav class="flex-1 space-y-1 px-4">
            @foreach ($nav as $item)
                <a href="{{ $item['href'] }}"
                    @if ($active === $item['key']) aria-current="page" @endif
                    @if ($item['soon']) aria-disabled="true" title="Em breve" @endif
                    @class([
                        'nav-item group flex items-center gap-3 py-3 font-body-md text-body-md transition-colors',
                        '-mx-4 px-6 font-semibold' => $active === $item['key'],
                        'rounded-control px-3' => $active !== $item['key'],
                    ])>
                    <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                    <span>{{ $item['label'] }}</span>
                    @if ($item['soon'])
                        <span class="ml-auto font-label-sm text-[10px] uppercase tracking-wide opacity-70">em breve</span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="px-4 pb-gutter">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                    class="nav-item nav-item--danger flex w-full items-center gap-3 rounded-control px-3 py-3 font-body-md text-body-md transition-colors">
                    <x-icon name="log-out" class="h-5 w-5 shrink-0" />
                    <span>Sair</span>
                </button>
            </form>
        </div>
    </aside>

    {{-- Coluna de conteúdo. Desloca 16rem à esquerda (aside) no desktop e reserva
         a largura da 3ª zona (chat, 380px) à direita a partir de lg — spec §7.14:
         nav · conteúdo · chat coexistem, o body reflui ENTRE eles (sem overlay). --}}
    <div class="flex min-h-screen flex-col md:pl-64 lg:pr-[380px]">
        {{-- Barra superior: hambúrguer (mobile) + título da página + sino --}}
        <header class="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-linha bg-superficie/90 px-container-padding-mobile backdrop-blur md:px-container-padding-desktop">
            <div class="flex min-w-0 items-center gap-3">
                <button type="button" id="sidebar-open"
                    class="-ml-1 rounded-full p-2 text-on-surface-variant transition-colors hover:bg-surface-container-high md:hidden"
                    aria-controls="app-sidebar" aria-expanded="false">
                    <x-icon name="menu" class="h-6 w-6" />
                    <span class="sr-only">Abrir menu</span>
                </button>
                <h1 class="truncate font-headline-md text-headline-md text-primary md:font-headline-md md:text-headline-md">{{ $heading ?? 'Agente Financeiro' }}</h1>
            </div>
            <div class="flex items-center gap-1">
                {{-- Lançador do chat: só abaixo de lg, onde a 3ª coluna recolhe para
                     folha. No desktop a coluna está sempre aberta e o botão some. --}}
                <button type="button" id="chat-open"
                    class="rounded-full p-2 text-on-surface-variant transition-colors hover:bg-surface-container-high lg:hidden"
                    aria-controls="chat-panel" aria-expanded="false">
                    <x-icon name="message-circle" class="h-6 w-6" />
                    <span class="sr-only">Abrir chat financeiro</span>
                </button>
                <x-app.notifications :notificacoes="$notificacoes" />
            </div>
        </header>

        {{-- Canvas principal. Estreito e centrado por padrão (telas de card único);
             largo e alinhado à esquerda no modo `wide` (dashboard, extratos). --}}
        <main class="flex-1 bg-surface-container-low">
            <div @class([
                'mx-auto w-full px-container-padding-mobile md:px-container-padding-desktop py-section-gap',
                'flex max-w-4xl flex-col items-center' => !$wide,
                'max-w-[1200px]' => $wide,
            ])>
                @if ($subheading)
                    <div @class(['mb-10 w-full', 'max-w-2xl text-center' => !$wide])>
                        <p class="font-body-md text-body-md text-on-surface-variant">{{ $subheading }}</p>
                    </div>
                @endif

                {{ $slot }}
            </div>
        </main>
    </div>

    {{-- Backdrop da folha do chat (só abaixo de lg, quando aberta). --}}
    <div id="chat-backdrop" hidden
        class="fixed inset-0 z-40 bg-inverse-surface/40 backdrop-blur-sm lg:hidden"></div>

    {{-- 3ª zona do shell: chat financeiro (spec §7.14). Fixa/aberta no desktop;
         folha deslizante no mobile. --}}
    <x-chat.panel />

    {{-- Toast (feedback efêmero) --}}
    <div id="toast" role="status" aria-live="polite"
        class="pointer-events-none fixed bottom-10 left-1/2 z-[100] -translate-x-1/2 translate-y-4 rounded-full bg-inverse-surface px-6 py-3 font-body-sm text-body-sm text-inverse-on-surface opacity-0 shadow-xl transition-all duration-300">
    </div>

    {{-- Drawer do menu (mobile): abre/fecha o aside. JS mínimo, sem framework. --}}
    <script>
        (function () {
            const aside = document.getElementById('app-sidebar');
            const backdrop = document.getElementById('sidebar-backdrop');
            const openBtn = document.getElementById('sidebar-open');
            const closeBtn = document.getElementById('sidebar-close');
            if (!aside) return;

            function setOpen(open) {
                aside.classList.toggle('-translate-x-full', !open);
                backdrop.hidden = !open;
                document.body.classList.toggle('overflow-hidden', open);
                openBtn?.setAttribute('aria-expanded', String(open));
            }

            openBtn?.addEventListener('click', () => setOpen(true));
            closeBtn?.addEventListener('click', () => setOpen(false));
            backdrop?.addEventListener('click', () => setOpen(false));
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') setOpen(false);
            });
            // Fecha ao navegar (mobile); no desktop o aside fica sempre visível.
            aside.querySelectorAll('a[href]:not([aria-disabled])').forEach((a) =>
                a.addEventListener('click', () => setOpen(false))
            );
        })();
    </script>

    @stack('scripts')
</body>

</html>
