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
        {{-- Marca --}}
        <div class="text-center mb-10">
            <h1 class="font-headline-lg-mobile md:font-headline-lg text-headline-lg-mobile md:text-headline-lg text-primary mb-2">
                Agente Financeiro
            </h1>
            <p class="font-body-md text-body-md text-outline">Sua gestão financeira, com a clareza do papel.</p>
        </div>

        {{-- Cartão de login --}}
        <section @class(['notebook-card rounded-xl p-8 md:p-10 transition-all duration-300', 'shake-horizontal' => $showError])>
            <form method="POST" action="{{ route('login.attempt') }}" class="space-y-6" data-login-form
                @if ($loading) aria-busy="true" @endif>
                @csrf

                {{-- E-mail --}}
                <div class="space-y-2">
                    <label for="email" class="block font-body-sm text-body-sm text-on-surface-variant">E-mail</label>
                    <input id="email" name="email" type="email" required autocomplete="email"
                        placeholder="seu@email.com" value="{{ old('email', $loading ? 'usuario@exemplo.com' : '') }}"
                        @disabled($loading)
                        @if ($showError) aria-invalid="true" aria-describedby="login-error" @endif
                        @class([
                            'input-field w-full rounded-lg px-4 py-3 font-body-md text-body-md text-on-surface',
                            'opacity-70 cursor-not-allowed' => $loading,
                        ])>
                </div>

                {{-- Senha --}}
                <div class="space-y-2">
                    <div class="flex justify-between items-center">
                        <label for="password" class="block font-body-sm text-body-sm text-on-surface-variant">Senha</label>
                        <a href="#" class="font-label-sm text-label-sm text-primary hover:underline">Esqueceu a senha?</a>
                    </div>
                    <div class="relative">
                        <input id="password" name="password" type="password" required autocomplete="current-password"
                            placeholder="••••••••"
                            @disabled($loading)
                            @if ($showError) aria-invalid="true" aria-describedby="login-error" @endif
                            @class([
                                'input-field w-full rounded-lg px-4 py-3 pr-12 font-body-md text-body-md text-on-surface',
                                'opacity-70 cursor-not-allowed' => $loading,
                            ])>
                        <button type="button" data-password-toggle aria-pressed="false" aria-label="Mostrar senha"
                            @disabled($loading)
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-outline hover:text-on-surface transition-colors disabled:opacity-50"></button>
                    </div>
                </div>

                {{-- Erro de credenciais (estado) --}}
                @if ($showError)
                    <div id="login-error" role="alert"
                        class="flex items-center gap-2 rounded-lg border border-argila/20 bg-argila/10 p-3">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" class="h-5 w-5 shrink-0 text-argila" aria-hidden="true">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="8" x2="12" y2="12" />
                            <line x1="12" y1="16" x2="12.01" y2="16" />
                        </svg>
                        <span class="font-body-sm text-body-sm font-medium text-argila">{{ $errorMessage }}</span>
                    </div>
                @endif

                {{-- Ação primária --}}
                <div class="pt-2">
                    @if ($loading)
                        <button type="submit" data-submit disabled aria-disabled="true"
                            class="w-full flex items-center justify-center gap-3 rounded-lg bg-primary-container py-3.5 font-body-md text-body-md text-on-primary-container cursor-not-allowed">
                            <span class="loading-spinner"></span><span>Entrando…</span>
                        </button>
                    @else
                        <button type="submit" data-submit
                            class="w-full rounded-lg bg-cedula py-3.5 font-body-md text-body-md text-superficie shadow-sm transition-colors duration-200 hover:bg-cedula-clara active:scale-[0.98]">
                            Entrar
                        </button>
                    @endif
                </div>
            </form>
        </section>

        {{-- Rodapé --}}
        <footer class="mt-8 text-center">
            <p class="font-body-sm text-body-sm text-outline">
                Não tem uma conta?
                <a href="#" class="font-semibold text-primary decoration-2 underline-offset-4 hover:underline">Criar conta</a>
            </p>
        </footer>
    </main>
</x-layouts.guest>
