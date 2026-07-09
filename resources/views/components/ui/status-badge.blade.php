@props(['status' => 'a_vencer'])

{{-- Selo de status de um lançamento/conta (spec FE §4.6). O status NUNCA é
     comunicado só por cor: o rótulo textual acompanha sempre (acessibilidade AA).
     Cores vêm dos tokens do design system — nada de hex solto. --}}
@php
    $mapa = [
        // Buckets do EXTRATO/lista (spec 06b): derivados por data.
        'pago' => ['rotulo' => 'Pago', 'classe' => 'bg-primary-container/10 text-primary'],
        'a_vencer' => ['rotulo' => 'A vencer', 'classe' => 'bg-ocre/10 text-ocre'],
        'atraso' => ['rotulo' => 'Atraso', 'classe' => 'bg-error/10 text-error'],
        'cancelado' => ['rotulo' => 'Cancelado', 'classe' => 'bg-surface-container-high text-on-surface-variant'],
        // Status POR PARCELA do detalhe (§7.8): distingue "em aberto" (vence hoje) de
        // "agendado" (futuro) e "vencido" (passado).
        'aberto' => ['rotulo' => 'Em aberto', 'classe' => 'bg-ocre/10 text-ocre'],
        'agendado' => ['rotulo' => 'Agendado', 'classe' => 'bg-surface-container-high text-on-surface-variant'],
        'vencido' => ['rotulo' => 'Vencido', 'classe' => 'bg-error/10 text-error'],
    ];
    $s = $mapa[$status] ?? $mapa['a_vencer'];
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full px-3 py-1 font-label-sm text-[10px] font-semibold uppercase tracking-wider', $s['classe']]) }}>
    {{ $s['rotulo'] }}
</span>
