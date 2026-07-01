@props([
    'title',
    'subtitle' => null,
    'updatedAt' => null,
    'current' => null, // 'terms' | 'privacy' — omite o próprio link no rodapé
])

{{-- Chrome comum dos documentos legais (públicos, indexáveis). Sem navbar do
     app — telas pré-login. O corpo entra no slot dentro de .legal-prose. --}}
<main class="w-full max-w-2xl">
    <div class="mb-6">
        <a href="{{ route('register') }}"
            class="inline-flex items-center gap-2 font-body-sm text-body-sm text-outline transition-colors hover:text-primary">
            <x-icon name="arrow-left" class="h-4 w-4 shrink-0" />
            Voltar para criar conta
        </a>
    </div>

    <article class="notebook-card rounded-xl p-8 md:p-12">
        <header class="mb-10 border-b border-linha pb-8">
            <p class="mb-3 font-label-sm text-label-sm uppercase text-primary">Agente Financeiro</p>
            <h1 class="font-headline-lg-mobile md:font-headline-lg text-headline-lg-mobile md:text-headline-lg text-on-surface">
                {{ $title }}
            </h1>
            @if ($subtitle)
                <p class="mt-3 font-body-md text-body-md text-on-surface-variant">{{ $subtitle }}</p>
            @endif
            @if ($updatedAt)
                <p class="mt-5 font-label-sm text-label-sm text-outline">Última atualização: {{ $updatedAt }}</p>
            @endif
        </header>

        <div class="legal-prose">
            {{ $slot }}
        </div>
    </article>

    <footer class="mt-10 flex flex-wrap items-center justify-center gap-x-3 gap-y-2 text-center font-body-sm text-body-sm">
        @if ($current !== 'terms')
            <a href="{{ route('terms') }}" class="font-medium text-primary hover:underline">Termos de Uso</a>
            <span class="text-outline-variant" aria-hidden="true">·</span>
        @endif
        @if ($current !== 'privacy')
            <a href="{{ route('privacy') }}" class="font-medium text-primary hover:underline">Política de Privacidade</a>
            <span class="text-outline-variant" aria-hidden="true">·</span>
        @endif
        <a href="{{ route('login') }}" class="text-outline hover:text-primary hover:underline">Entrar</a>
    </footer>
</main>
