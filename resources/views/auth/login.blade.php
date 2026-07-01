@php
    // Estados da tela (apresentação). O erro real vem do $errors do backend;
    // ?preview=erro|carregando permite revisar cada estado no design.
    $preview = request('preview');
    $showError = $errors->any() || $preview === 'erro';
    $errorMessage = $errors->first() ?: 'E-mail ou senha incorretos.';
    $loading = $preview === 'carregando';
@endphp

<x-layouts.guest title="Entrar | Agente Financeiro">
    @push('scripts')
        @vite('resources/js/pages/auth.js')
    @endpush

    <main class="w-full max-w-md">
        <x-auth.brand />

        {{-- Cartão de login --}}
        <section @class(['notebook-card rounded-xl p-8 md:p-10 transition-all duration-300', 'shake-horizontal' => $showError])>
            <form method="POST" action="{{ route('login.attempt') }}" class="space-y-6" data-loading-form
                @if ($loading) aria-busy="true" @endif>
                @csrf

                <x-form.field name="email" label="E-mail" type="email" placeholder="seu@email.com"
                    autocomplete="email" :required="true" :disabled="$loading" />

                <x-form.field name="password" label="Senha" type="password" placeholder="••••••••"
                    autocomplete="current-password" :required="true" :toggle="true" :disabled="$loading">
                    <x-slot:action>
                        <a href="#" class="font-label-sm text-label-sm text-primary hover:underline">Esqueceu a senha?</a>
                    </x-slot:action>
                </x-form.field>

                {{-- Erro de credenciais (estado) --}}
                @if ($showError)
                    <div id="login-error" role="alert"
                        class="flex items-center gap-2 rounded-lg border border-argila/20 bg-argila/10 p-3">
                        <x-icon name="alert" class="h-5 w-5 shrink-0 text-argila" />
                        <span class="font-body-sm text-body-sm font-medium text-argila">{{ $errorMessage }}</span>
                    </div>
                @endif

                {{-- Ação primária --}}
                <div class="pt-2">
                    <button type="submit" data-submit data-loading-label="Entrando…" @disabled($loading)
                        @class([
                            'w-full rounded-lg py-3.5 font-body-md text-body-md transition-colors duration-200',
                            'flex items-center justify-center gap-3 bg-primary-container text-on-primary-container cursor-not-allowed' => $loading,
                            'bg-cedula text-superficie shadow-sm hover:bg-cedula-clara active:scale-[0.98]' => ! $loading,
                        ])>
                        @if ($loading)
                            <span class="loading-spinner"></span><span>Entrando…</span>
                        @else
                            Entrar
                        @endif
                    </button>
                </div>
            </form>
        </section>

        {{-- Rodapé --}}
        <footer class="mt-8 space-y-4 text-center">
            <p class="font-body-sm text-body-sm text-outline">
                Não tem uma conta?
                <a href="{{ route('register') }}"
                    class="font-semibold text-primary decoration-2 underline-offset-4 hover:underline">Criar conta</a>
            </p>
            <p class="font-label-sm text-label-sm text-outline">
                <a href="{{ route('terms') }}" class="hover:text-primary hover:underline">Termos de Uso</a>
                <span class="mx-2 opacity-50" aria-hidden="true">·</span>
                <a href="{{ route('privacy') }}" class="hover:text-primary hover:underline">Política de Privacidade</a>
            </p>
        </footer>
    </main>
</x-layouts.guest>
