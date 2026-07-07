{{-- Uma bolha do chat financeiro (spec FE §7.14). Renderiza uma mensagem já persistida
     (histórico real). O mesmo layout é reproduzido pelo chat.js ao anexar mensagens novas
     em tempo real — mantenha os dois em sintonia. Valores em R$ vão em MONO (assinatura do
     design system); a tela nunca calcula (regra 4). --}}
@props([
    'role',           // 'user' | 'assistant'
    'body' => '',
    'temAnexo' => false,
])

@php
    use App\Support\TextoDoChat;
@endphp

@if ($role === 'user')
    <div class="flex justify-end">
        <div class="max-w-[85%] space-y-2 rounded-card rounded-tr-sm bg-papel px-4 py-3">
            @if ($temAnexo)
                <div class="flex items-center gap-2 rounded-control border border-linha bg-superficie/80 px-3 py-2">
                    <x-icon name="file-text" class="h-4 w-4 shrink-0 text-on-surface-variant" />
                    <span class="truncate font-label-sm text-label-sm text-on-surface">documento PDF</span>
                </div>
            @endif
            @if ($body !== '')
                <p class="font-body-md text-body-md text-on-surface">{{ $body }}</p>
            @endif
        </div>
    </div>
@else
    <div class="flex justify-start">
        <div class="notebook-card max-w-[90%] rounded-card rounded-tl-sm px-4 py-4">
            <p class="font-body-md text-body-md text-on-surface">{!! TextoDoChat::comValoresMono($body) !!}</p>
        </div>
    </div>
@endif
