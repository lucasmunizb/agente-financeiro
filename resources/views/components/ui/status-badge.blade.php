@props(['status' => 'a_vencer'])

{{-- Selo de status de um lançamento/conta (spec FE §4.6). O status NUNCA é
     comunicado só por cor: o rótulo textual acompanha sempre (acessibilidade AA).
     Cores vêm dos tokens do design system — nada de hex solto. --}}
@php
    $mapa = [
        'pago' => ['rotulo' => 'Pago', 'classe' => 'bg-primary-container/10 text-primary'],
        'a_vencer' => ['rotulo' => 'A vencer', 'classe' => 'bg-ocre/10 text-ocre'],
        'atraso' => ['rotulo' => 'Atraso', 'classe' => 'bg-error/10 text-error'],
        'cancelado' => ['rotulo' => 'Cancelado', 'classe' => 'bg-surface-container-high text-on-surface-variant'],
    ];
    $s = $mapa[$status] ?? $mapa['a_vencer'];
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full px-3 py-1 font-label-sm text-[10px] font-semibold uppercase tracking-wider', $s['classe']]) }}>
    {{ $s['rotulo'] }}
</span>
