@props([
    'action',
    'method' => 'POST',           // POST (criar) ou PUT (editar, via spoof)
    'categoria' => null,          // array de prefill na edição; null ao criar
    'cores' => [],                // paleta de cores (#RRGGBB)
    'icones' => [],               // paleta de ícones de linha (nomes do x-icon)
    'submit' => 'Salvar',
    'errors' => null,             // MessageBag DESTE formulário (ou null); decide o repovoamento
])

@php
    /** @var \Illuminate\Support\MessageBag $bag */
    $bag = $errors ?? new \Illuminate\Support\MessageBag;
    // Só repovoa com old() quando ESTE formulário foi o que falhou (evita vazar input de um
    // formulário para os outros da mesma página). Senão usa o prefill da categoria (edição).
    $usarOld = $bag->isNotEmpty();
    $fid = $categoria['opaqueId'] ?? 'nova';

    $nome = $usarOld ? old('nome') : ($categoria['nome'] ?? '');
    $corSel = $usarOld ? old('cor') : ($categoria['corSelecionada'] ?? '');
    $iconeSel = $usarOld ? old('icone') : ($categoria['icone'] ?? '');
    $palavras = $usarOld ? old('palavras_chave') : ($categoria['palavrasChave'] ?? '');
    $apelidos = $usarOld ? old('apelidos') : ($categoria['apelidos'] ?? '');
@endphp

<form method="POST" action="{{ $action }}" class="flex flex-col gap-5">
    @csrf
    @if (strtoupper($method) === 'PUT')
        @method('PUT')
        {{-- Marca qual categoria falhou, para a tela reabrir o formulário certo. --}}
        <input type="hidden" name="_categoria" value="{{ $categoria['opaqueId'] ?? '' }}">
    @endif

    {{-- Nome (obrigatório). --}}
    <div class="flex flex-col gap-1.5">
        <label for="cat-nome-{{ $fid }}" class="font-body-sm text-body-sm font-medium text-on-surface">Nome <span class="text-argila">*</span></label>
        <input id="cat-nome-{{ $fid }}" name="nome" type="text" value="{{ $nome }}" maxlength="60"
            autocomplete="off" @if ($usarOld) autofocus @endif
            @class([
                'input-field h-11 w-full rounded-lg px-3 font-body-md text-body-md text-on-surface',
                'border-argila focus:border-argila' => $bag->has('nome'),
            ]) />
        @if ($bag->has('nome'))
            <p class="flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
                <x-icon name="alert" class="h-4 w-4 shrink-0" />{{ $bag->first('nome') }}
            </p>
        @endif
    </div>

    {{-- Cor (paleta fixa; opcional). Radios nativos: seleção única, teclado, submete sem JS. --}}
    <fieldset class="flex flex-col gap-2">
        <legend class="mb-1 font-body-sm text-body-sm font-medium text-on-surface">Cor</legend>
        <div class="flex flex-wrap items-center gap-2.5">
            <label class="cursor-pointer">
                <input type="radio" name="cor" value="" class="peer sr-only" @checked(! $corSel)>
                <span class="flex h-8 w-8 items-center justify-center rounded-full border border-linha text-on-surface-variant ring-2 ring-transparent transition peer-checked:ring-tinta peer-focus-visible:ring-primary" title="Sem cor" aria-label="Sem cor">
                    <x-icon name="circle" class="h-4 w-4" />
                </span>
            </label>
            @foreach ($cores as $c)
                <label class="cursor-pointer">
                    <input type="radio" name="cor" value="{{ $c }}" class="peer sr-only" @checked($corSel === $c) aria-label="Cor {{ $c }}">
                    <span @class(['block h-8 w-8 rounded-full ring-2 ring-transparent ring-offset-2 ring-offset-superficie transition peer-checked:ring-tinta peer-focus-visible:ring-primary', \App\Domain\Categoria\PaletaDeCategoria::classe($c)])></span>
                </label>
            @endforeach
        </div>
        @if ($bag->has('cor'))
            <p class="flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
                <x-icon name="alert" class="h-4 w-4 shrink-0" />{{ $bag->first('cor') }}
            </p>
        @endif
    </fieldset>

    {{-- Ícone (paleta fixa de linha; opcional). --}}
    <fieldset class="flex flex-col gap-2">
        <legend class="mb-1 font-body-sm text-body-sm font-medium text-on-surface">Ícone</legend>
        <div class="flex flex-wrap items-center gap-2">
            @foreach ($icones as $ic)
                <label class="cursor-pointer">
                    <input type="radio" name="icone" value="{{ $ic }}" class="peer sr-only" @checked($iconeSel === $ic) aria-label="Ícone {{ $ic }}">
                    <span class="flex h-10 w-10 items-center justify-center rounded-control border border-linha text-on-surface-variant ring-2 ring-transparent transition peer-checked:border-cedula peer-checked:bg-cedula/5 peer-checked:text-cedula peer-focus-visible:ring-primary">
                        <x-icon :name="$ic" class="h-5 w-5" />
                    </span>
                </label>
            @endforeach
        </div>
        @if ($bag->has('icone'))
            <p class="flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
                <x-icon name="alert" class="h-4 w-4 shrink-0" />{{ $bag->first('icone') }}
            </p>
        @endif
    </fieldset>

    {{-- Regras do lookup (opcionais). Texto separado por vírgula; o backend normaliza e deduplica. --}}
    <div class="flex flex-col gap-1.5">
        <label for="cat-palavras-{{ $fid }}" class="font-body-sm text-body-sm font-medium text-on-surface">Palavras-chave</label>
        <input id="cat-palavras-{{ $fid }}" name="palavras_chave" type="text" value="{{ $palavras }}"
            autocomplete="off" placeholder="mercado, restaurante, ifood"
            class="input-field h-11 w-full rounded-lg px-3 font-body-md text-body-md text-on-surface" />
        <p class="font-label-sm text-label-sm text-outline">Separe por vírgula. Ajudam a classificar os lançamentos.</p>
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="cat-apelidos-{{ $fid }}" class="font-body-sm text-body-sm font-medium text-on-surface">Apelidos de estabelecimento</label>
        <input id="cat-apelidos-{{ $fid }}" name="apelidos" type="text" value="{{ $apelidos }}"
            autocomplete="off" placeholder="pão de açúcar, ifood"
            class="input-field h-11 w-full rounded-lg px-3 font-body-md text-body-md text-on-surface" />
        <p class="font-label-sm text-label-sm text-outline">Separe por vírgula. Sempre classificam o mesmo estabelecimento.</p>
    </div>

    <button type="submit"
        class="inline-flex h-11 w-full items-center justify-center rounded-control bg-cedula px-6 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-cedula-clara focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-surface-container-low active:scale-95">
        {{ $submit }}
    </button>
</form>
