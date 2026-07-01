@php
    $consentError = $errors->first('consent');
@endphp

<x-layouts.guest title="Antes de começar | Agente Financeiro">
    @push('scripts')
        @vite('resources/js/pages/onboarding.js')
    @endpush

    <main class="w-full max-w-lg mx-auto">
        <section class="notebook-card rounded-xl p-8 md:p-12 transition-all duration-300">
            {{-- Cabeçalho --}}
            <header class="mb-10 text-center">
                <div class="mb-6 flex justify-center">
                    <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-primary-container text-on-primary-container">
                        <x-icon name="shield-check" class="h-7 w-7" />
                    </div>
                </div>
                <h1 class="font-headline-lg-mobile md:font-headline-lg text-headline-lg-mobile md:text-headline-lg text-on-surface mb-2">
                    Antes de começar
                </h1>
                <p class="font-body-md text-body-md text-on-surface-variant">
                    Para garantir a sua segurança e transparência no uso dos seus dados.
                </p>
            </header>

            {{-- Blocos informativos --}}
            <div class="mb-10 space-y-8">
                @foreach ([['wallet', 'Acompanhe seus gastos', 'Registre seus movimentos financeiros na plataforma web ou diretamente via Telegram para maior agilidade.'], ['cpu', 'IA que interpreta, não inventa', 'Nossa IA classifica categorias e redige resumos, mas os números vêm exclusivamente do seu banco de dados, calculados matematicamente pelo sistema.'], ['lock', 'Privacidade', 'PDFs de fatura são processados e descartados imediatamente. Conversas são mantidas por até 60 dias para histórico; nenhum dado sensível é armazenado permanentemente.']] as [$icon, $titulo, $texto])
                    <div class="flex items-start gap-4">
                        <x-icon :name="$icon" class="mt-1 h-6 w-6 shrink-0 text-primary" />
                        <div>
                            <h2 class="font-headline-md text-headline-md text-on-surface mb-1">{{ $titulo }}</h2>
                            <p class="font-body-sm text-body-sm leading-relaxed text-on-surface-variant">{{ $texto }}</p>
                        </div>
                    </div>
                @endforeach
            </div>

            <form method="POST" action="{{ route('onboarding.consent') }}" data-loading-form>
                @csrf

                {{-- Consentimento (LGPD): botão só habilita ao marcar --}}
                <div @class([
                    'rounded-lg border p-5',
                    'border-argila/30 bg-argila/10' => filled($consentError),
                    'border-outline-variant bg-surface-container-low' => ! filled($consentError),
                ])>
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="checkbox" name="consent" value="1" data-consent-check @checked(old('consent'))
                            class="mt-1 h-5 w-5 shrink-0 rounded accent-cedula"
                            @if (filled($consentError)) aria-invalid="true" aria-describedby="consent-error" @endif>
                        <span class="font-body-sm text-body-sm select-none text-on-surface">
                            Concordo com o tratamento dos meus dados para as finalidades acima e li a
                            <a href="{{ route('privacy') }}" class="text-primary underline underline-offset-2 hover:text-primary-container">política
                                de privacidade</a>.
                        </span>
                    </label>
                    @if (filled($consentError))
                        <p id="consent-error" class="mt-2 flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
                            <x-icon name="alert" class="h-4 w-4 shrink-0" />
                            <span>{{ $consentError }}</span>
                        </p>
                    @endif
                </div>

                {{-- Ação --}}
                <div class="mt-8 flex flex-col gap-4">
                    <button type="submit" data-submit data-loading-label="Configurando…" data-consent-submit disabled
                        class="w-full rounded-lg px-6 py-4 font-body-md text-body-md font-semibold text-superficie shadow-sm transition-colors duration-200 bg-cedula hover:bg-cedula-clara active:scale-[0.98] disabled:cursor-not-allowed disabled:bg-outline-variant disabled:opacity-60 disabled:hover:bg-outline-variant">
                        Começar
                    </button>
                    <p class="text-center font-label-sm text-label-sm text-outline opacity-80">
                        Você pode excluir seus dados quando quiser.
                    </p>
                </div>
            </form>
        </section>

        {{-- Referência de marca discreta --}}
        <div class="mt-8 text-center">
            <span class="font-headline-md text-headline-md font-bold text-primary opacity-20 select-none">Agente Financeiro</span>
        </div>
    </main>
</x-layouts.guest>
