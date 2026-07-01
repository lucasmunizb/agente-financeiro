@php
    // Estados (apresentação): ?preview=erros mostra os erros por campo;
    // ?preview=carregando mostra o botão em processamento.
    $preview = request('preview');
    $loading = $preview === 'carregando';
    $previewErrors = $preview === 'erros'
        ? [
            'email' => 'Use um e-mail válido.',
            'password_confirmation' => 'As senhas não conferem.',
            'terms' => 'Aceite os termos para continuar.',
        ]
        : [];
    $termsError = $errors->first('terms') ?: ($previewErrors['terms'] ?? null);
    $shake = $errors->any() || $preview === 'erros';
@endphp

<x-layouts.guest title="Criar conta | Agente Financeiro">
    @push('scripts')
        @vite('resources/js/pages/auth.js')
    @endpush

    <main class="w-full max-w-md">
        <x-auth.brand heading="Crie sua conta"
            tagline="Comece sua jornada para uma vida financeira mais organizada e calma." />

        {{-- Cartão de cadastro --}}
        <section @class(['notebook-card rounded-xl p-8 md:p-10 transition-all duration-300', 'shake-horizontal' => $shake])>
            <form method="POST" action="{{ route('register.attempt') }}" class="space-y-6" data-loading-form
                @if ($loading) aria-busy="true" @endif>
                @csrf

                {{-- data-bwignore: o Bitwarden ignora este campo (não oferece salvar/autofill). --}}
                <x-form.field name="name" label="Nome completo" type="text" placeholder="Ex: João Silva"
                    autocomplete="name" :required="true" :disabled="$loading" data-bwignore="true" />

                <x-form.field name="email" label="E-mail" type="email" placeholder="seu@email.com"
                    autocomplete="email" :required="true" :disabled="$loading"
                    :error="$previewErrors['email'] ?? null" />

                <x-form.field name="password" label="Senha" type="password" placeholder="••••••••"
                    autocomplete="new-password" :required="true" :disabled="$loading" data-bwignore="true" />

                <x-form.field name="password_confirmation" label="Confirmar senha" type="password"
                    placeholder="••••••••" autocomplete="new-password" :required="true" :disabled="$loading"
                    :error="$previewErrors['password_confirmation'] ?? null" />

                {{-- Aceite dos termos --}}
                <div>
                    <label @class([
                        'flex items-start gap-3 rounded-lg border p-3 cursor-pointer',
                        'border-argila/20 bg-argila/10' => filled($termsError),
                        'border-linha' => ! filled($termsError),
                    ])>
                        <input type="checkbox" name="terms" value="1" @checked(old('terms')) @disabled($loading)
                            class="mt-0.5 h-5 w-5 shrink-0 rounded accent-cedula"
                            @if (filled($termsError)) aria-invalid="true" aria-describedby="terms-error" @endif>
                        <span class="font-body-sm text-body-sm leading-tight text-on-surface">
                            Li e aceito os
                            <a href="{{ route('terms') }}" class="font-medium text-primary underline underline-offset-2">Termos de Uso</a>
                            e a
                            <a href="{{ route('privacy') }}" class="font-medium text-primary underline underline-offset-2">Política de
                                Privacidade</a>.
                        </span>
                    </label>
                    @if (filled($termsError))
                        <p id="terms-error" class="mt-2 flex items-center gap-1.5 px-1 font-label-sm text-label-sm text-argila">
                            <x-icon name="alert" class="h-4 w-4 shrink-0" />
                            <span>{{ $termsError }}</span>
                        </p>
                    @endif
                </div>

                {{-- Ação primária --}}
                <div class="pt-2">
                    <button type="submit" data-submit data-loading-label="Criando conta…" @disabled($loading)
                        @class([
                            'w-full rounded-lg py-3.5 font-body-md text-body-md transition-colors duration-200',
                            'flex items-center justify-center gap-3 bg-primary-container text-on-primary-container cursor-not-allowed' => $loading,
                            'bg-cedula text-superficie shadow-sm hover:bg-cedula-clara active:scale-[0.98]' => ! $loading,
                        ])>
                        @if ($loading)
                            <span class="loading-spinner"></span><span>Criando conta…</span>
                        @else
                            Criar minha conta
                        @endif
                    </button>
                </div>
            </form>
        </section>

        {{-- Rodapé --}}
        <footer class="mt-8 text-center">
            <p class="font-body-sm text-body-sm text-outline">
                Já possui uma conta?
                <a href="{{ route('login') }}"
                    class="font-semibold text-primary decoration-2 underline-offset-4 hover:underline">Fazer login</a>
            </p>
        </footer>
    </main>
</x-layouts.guest>
