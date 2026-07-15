{{-- Configurações & privacidade (spec FE §7.17). Agrega perfil, preferências, vínculo do Telegram,
     transparência de IA e os direitos LGPD (portabilidade + exclusão). Tudo vem PRONTO do backend
     (ConfiguracoesController → domínio App\Domain\Conta): a UI só exibe e captura (regras 3/4).
     Nada é gravado/excluído sem confirmação (regra 7); a exclusão exige dupla confirmação. --}}
@php
    $bagPerfil = $errors->getBag('perfil');
    $bagSenha = $errors->getBag('senha');
    $bagExcluir = $errors->getBag('excluir');
@endphp
<x-layouts.app title="Configurações | Agente Financeiro" active="configuracoes" heading="Configurações">
    <div class="flex w-full max-w-2xl flex-col gap-8">
        <header class="flex flex-col gap-1">
            <h2 class="font-display text-headline-lg font-semibold text-on-surface">Configurações</h2>
            <p class="font-body-sm text-body-sm text-on-surface-variant">Seu perfil, preferências e privacidade.</p>
        </header>

        {{-- 1+2. Perfil e Preferências — um único form (nome, e-mail, fuso). --}}
        <form method="POST" action="{{ route('configuracoes.perfil') }}" class="flex flex-col gap-6">
            @csrf
            @method('PUT')

            <article class="notebook-card flex flex-col gap-5 rounded-card p-6">
                <h3 class="font-display text-headline-md font-semibold text-on-surface">Perfil</h3>

                <div class="flex flex-col gap-2">
                    <label for="cfg-nome" class="font-body-sm text-body-sm text-on-surface-variant">Nome</label>
                    <input id="cfg-nome" name="name" type="text" autocomplete="name" value="{{ old('name', $user->name) }}"
                        @class([
                            'input-field h-12 w-full rounded-lg px-4 font-body-md text-body-md text-on-surface',
                            'border-argila focus:border-argila' => $bagPerfil->has('name'),
                        ]) />
                    @if ($bagPerfil->has('name'))
                        <p class="flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
                            <x-icon name="alert" class="h-4 w-4 shrink-0" />{{ $bagPerfil->first('name') }}
                        </p>
                    @endif
                </div>

                <div class="flex flex-col gap-2">
                    <label for="cfg-email" class="font-body-sm text-body-sm text-on-surface-variant">E-mail</label>
                    <input id="cfg-email" name="email" type="email" autocomplete="email" value="{{ old('email', $user->email) }}"
                        @class([
                            'input-field h-12 w-full rounded-lg px-4 font-body-md text-body-md text-on-surface',
                            'border-argila focus:border-argila' => $bagPerfil->has('email'),
                        ]) />
                    @if ($bagPerfil->has('email'))
                        <p class="flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
                            <x-icon name="alert" class="h-4 w-4 shrink-0" />{{ $bagPerfil->first('email') }}
                        </p>
                    @endif
                </div>
            </article>

            <article class="notebook-card flex flex-col gap-5 rounded-card p-6">
                <h3 class="font-display text-headline-md font-semibold text-on-surface">Preferências</h3>

                <div class="flex flex-col gap-2">
                    <label for="cfg-fuso" class="font-body-sm text-body-sm text-on-surface-variant">Fuso horário</label>
                    <select id="cfg-fuso" name="timezone"
                        @class([
                            'input-field h-12 w-full rounded-lg px-4 font-body-md text-body-md text-on-surface',
                            'border-argila focus:border-argila' => $bagPerfil->has('timezone'),
                        ])>
                        @foreach ($fusos as $fuso)
                            <option value="{{ $fuso }}" @selected(old('timezone', $user->timezone) === $fuso)>
                                {{ str_replace(['America/', '_'], ['', ' '], $fuso) }}
                            </option>
                        @endforeach
                    </select>
                    <p class="font-label-sm text-label-sm text-on-surface-variant">
                        Usado no seu perfil. Os cálculos de datas e vencimentos seguem o horário de São Paulo.
                    </p>
                    @if ($bagPerfil->has('timezone'))
                        <p class="flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
                            <x-icon name="alert" class="h-4 w-4 shrink-0" />{{ $bagPerfil->first('timezone') }}
                        </p>
                    @endif
                </div>

                <div class="flex flex-col gap-2">
                    <label class="font-body-sm text-body-sm text-on-surface-variant">Mês de referência</label>
                    <p class="font-value-label text-value-label text-on-surface">Mês corrente</p>
                </div>
            </article>

            <button type="submit"
                class="inline-flex h-11 w-full max-w-xs items-center justify-center rounded-control bg-cedula px-6 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-cedula-clara focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-surface-container-low active:scale-95">
                Salvar alterações
            </button>
        </form>

        {{-- Senha — form próprio (não pode aninhar no de perfil). --}}
        <article class="notebook-card flex flex-col gap-4 rounded-card p-6">
            <h3 class="font-display text-headline-md font-semibold text-on-surface">Senha</h3>
            <details @if ($bagSenha->isNotEmpty()) open @endif>
                <summary class="inline-flex h-11 w-full max-w-xs cursor-pointer list-none items-center justify-center gap-2 rounded-control border border-cedula px-6 font-body-md text-body-md font-medium text-cedula transition-colors hover:bg-cedula/5 focus:outline-none focus:ring-2 focus:ring-primary">
                    <x-icon name="lock" class="h-4 w-4" /> Alterar senha
                </summary>
                <form method="POST" action="{{ route('configuracoes.senha') }}" class="mt-4 flex max-w-sm flex-col gap-4">
                    @csrf
                    @method('PUT')
                    <div class="flex flex-col gap-2">
                        <label for="cfg-senha-atual" class="font-body-sm text-body-sm text-on-surface-variant">Senha atual</label>
                        <input id="cfg-senha-atual" name="senha_atual" type="password" autocomplete="current-password"
                            @class([
                                'input-field h-12 w-full rounded-lg px-4 font-body-md text-body-md text-on-surface',
                                'border-argila focus:border-argila' => $bagSenha->has('senha_atual'),
                            ]) />
                        @if ($bagSenha->has('senha_atual'))
                            <p class="flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
                                <x-icon name="alert" class="h-4 w-4 shrink-0" />{{ $bagSenha->first('senha_atual') }}
                            </p>
                        @endif
                    </div>
                    <div class="flex flex-col gap-2">
                        <label for="cfg-senha" class="font-body-sm text-body-sm text-on-surface-variant">Nova senha</label>
                        <input id="cfg-senha" name="senha" type="password" autocomplete="new-password"
                            @class([
                                'input-field h-12 w-full rounded-lg px-4 font-body-md text-body-md text-on-surface',
                                'border-argila focus:border-argila' => $bagSenha->has('senha'),
                            ]) />
                        @if ($bagSenha->has('senha'))
                            <p class="flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
                                <x-icon name="alert" class="h-4 w-4 shrink-0" />{{ $bagSenha->first('senha') }}
                            </p>
                        @endif
                    </div>
                    <div class="flex flex-col gap-2">
                        <label for="cfg-senha-conf" class="font-body-sm text-body-sm text-on-surface-variant">Confirmar nova senha</label>
                        <input id="cfg-senha-conf" name="senha_confirmation" type="password" autocomplete="new-password"
                            class="input-field h-12 w-full rounded-lg px-4 font-body-md text-body-md text-on-surface" />
                    </div>
                    <button type="submit"
                        class="inline-flex h-11 items-center justify-center rounded-control bg-cedula px-6 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-cedula-clara focus:outline-none focus:ring-2 focus:ring-primary active:scale-95">
                        Alterar senha
                    </button>
                </form>
            </details>
        </article>

        {{-- 3. Telegram --}}
        <article class="notebook-card flex flex-col gap-4 rounded-card p-6">
            <h3 class="font-display text-headline-md font-semibold text-on-surface">Telegram</h3>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    @if ($telegramVinculado)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-container/20 px-3 py-1 font-label-sm text-label-sm font-medium text-primary">
                            <x-icon name="check" class="h-4 w-4" /> Conectado
                        </span>
                        <span class="font-value-label text-value-label text-on-surface">{{ $telegramHandle }}</span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-container px-3 py-1 font-label-sm text-label-sm text-on-surface-variant">
                            Não conectado
                        </span>
                    @endif
                </div>
                <a href="{{ route('telegram') }}"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-control border border-linha px-5 font-body-md text-body-md font-medium text-on-surface transition-colors hover:bg-surface-container focus:outline-none focus:ring-2 focus:ring-primary">
                    <x-icon name="send" class="h-4 w-4" /> Gerenciar vínculo
                </a>
            </div>
        </article>

        {{-- 4. IA e transparência --}}
        <article class="notebook-card flex flex-col gap-4 rounded-card p-6">
            <h3 class="font-display text-headline-md font-semibold text-on-surface">IA e transparência</h3>
            <p class="font-body-md text-body-md text-on-surface-variant">
                A IA faz três coisas: classifica seus gastos, extrai dados de faturas e redige as respostas.
                A IA nunca calcula dinheiro — os números vêm do seu banco de dados.
            </p>
            <a href="{{ route('privacy') }}"
                class="inline-flex items-center gap-1.5 font-body-md text-body-md font-medium text-cedula hover:underline focus:outline-none focus:ring-2 focus:ring-primary">
                <x-icon name="file-text" class="h-4 w-4" /> Política de privacidade
            </a>
        </article>

        {{-- 5. Privacidade (LGPD) --}}
        <article class="notebook-card flex flex-col gap-4 rounded-card p-6">
            <h3 class="font-display text-headline-md font-semibold text-on-surface">Privacidade</h3>
            <p class="font-body-md text-body-md text-on-surface-variant">Conversas são guardadas por até 60 dias.</p>

            <a href="{{ route('configuracoes.exportar') }}"
                class="inline-flex h-11 w-full max-w-xs items-center justify-center gap-2 rounded-control border border-linha px-5 font-body-md text-body-md font-medium text-on-surface transition-colors hover:bg-surface-container focus:outline-none focus:ring-2 focus:ring-primary">
                <x-icon name="database" class="h-4 w-4" /> Baixar meus dados
            </a>

            {{-- Ação destrutiva, claramente separada, em argila (regra 7: dupla confirmação). --}}
            <div class="mt-2 pt-5">
                <details data-excluir-conta @if ($bagExcluir->isNotEmpty()) open @endif>
                    <summary class="inline-flex h-11 cursor-pointer list-none items-center justify-center gap-2 rounded-control border border-argila px-5 font-body-md text-body-md font-medium text-argila transition-colors hover:bg-argila/5 focus:outline-none focus:ring-2 focus:ring-argila">
                        <x-icon name="alert" class="h-4 w-4" /> Excluir minha conta
                    </summary>
                    <div class="mt-4 flex flex-col gap-3 rounded-card border border-argila/40 bg-argila/5 p-5">
                        <h4 class="font-display text-headline-md font-semibold text-on-surface">Excluir minha conta</h4>
                        <p class="font-body-sm text-body-sm text-on-surface-variant">
                            Isto encerra sua conta e você perde o acesso aos seus lançamentos. Esta ação não pode ser desfeita.
                        </p>
                        <form method="POST" action="{{ route('configuracoes.excluir') }}" class="flex flex-col gap-3">
                            @csrf
                            @method('DELETE')
                            <div class="flex flex-col gap-2">
                                <label for="cfg-excluir" class="font-body-sm text-body-sm text-on-surface-variant">Digite EXCLUIR para confirmar</label>
                                <input id="cfg-excluir" name="confirmacao" type="text" autocomplete="off" data-excluir-input
                                    @class([
                                        'input-field h-12 w-full max-w-xs rounded-lg px-4 font-value-label text-value-label text-on-surface',
                                        'border-argila focus:border-argila' => $bagExcluir->has('confirmacao'),
                                    ]) />
                                @if ($bagExcluir->has('confirmacao'))
                                    <p class="flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
                                        <x-icon name="alert" class="h-4 w-4 shrink-0" />{{ $bagExcluir->first('confirmacao') }}
                                    </p>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <button type="submit" data-excluir-submit disabled
                                    class="inline-flex h-11 items-center justify-center rounded-control bg-argila px-6 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-argila/90 focus:outline-none focus:ring-2 focus:ring-argila disabled:cursor-not-allowed disabled:opacity-50">
                                    Excluir definitivamente
                                </button>
                                <a href="{{ route('configuracoes') }}"
                                    class="inline-flex h-11 items-center justify-center rounded-control px-5 font-body-md text-body-md font-medium text-on-surface-variant transition-colors hover:bg-surface-container focus:outline-none focus:ring-2 focus:ring-primary">
                                    Cancelar
                                </a>
                            </div>
                        </form>
                    </div>
                </details>
            </div>
        </article>
    </div>

    {{-- Cortesia de latência do "Excluir definitivamente": resources/js/pages/configuracoes.js
         (script inline era bloqueado pela CSP estrita de produção — P3-13). --}}
    @push('scripts')
        @vite('resources/js/pages/configuracoes.js')
    @endpush
</x-layouts.app>
