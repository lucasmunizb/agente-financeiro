@props([
    'descricao',
    'valor',            // já formatado em pt-BR pelo backend (regra 5)
    'categoria' => null, // ['nome' => ..., 'cor' => ...] ou null
    'formaLabel' => '',  // cartão (descrição) ou forma pt-BR
    'formaIcone' => 'wallet',
    'parcela' => null,   // "2/3" ou null
    'status' => 'a_vencer', // pago | a_vencer | atraso | cancelado
    'href' => null,      // quando presente, a linha vira link (editar)
])

{{-- Linha de lançamento no estilo EXTRATO (spec FE §4.6/§7.6): descrição + chip de
     categoria + forma/cartão à esquerda; valor em mono e selo de status à direita. A
     ruling (borda) para 16px antes da borda do card, como num caderno. Nada é calculado
     aqui — os valores chegam prontos do backend (regra 4). Com `href`, a linha inteira
     é um link para editar o lançamento. --}}
@php $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    class="notebook-line flex items-center justify-between gap-4 px-4 py-4 transition-colors @if ($href) hover:bg-surface-container-low @endif">
    <div class="flex min-w-0 flex-col gap-1">
        <span class="truncate font-body-md text-body-md text-on-surface">{{ $descricao }}</span>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            @if ($categoria)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-container px-2 py-0.5 font-label-sm text-label-sm text-on-surface-variant">
                    <span class="h-2 w-2 shrink-0 rounded-full"
                        style="background-color: {{ $categoria['cor'] ?? '#6B6F66' }}"></span>
                    {{ $categoria['nome'] }}
                </span>
            @endif
            <span class="inline-flex items-center gap-1 font-body-sm text-[12px] text-outline">
                <x-icon :name="$formaIcone" class="h-3.5 w-3.5" />
                {{ $formaLabel }}@if ($parcela) <span class="font-value-label">({{ $parcela }})</span>@endif
            </span>
        </div>
    </div>
    <div class="flex shrink-0 flex-col items-end gap-1">
        <span class="font-value-label text-value-label text-on-surface">{{ $valor }}</span>
        <x-ui.status-badge :status="$status" />
    </div>
</{{ $tag }}>
