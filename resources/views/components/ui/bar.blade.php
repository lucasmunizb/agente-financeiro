@props(['pct' => 0])
{{--
    Barra de progresso com largura DINÂMICA sem style inline (CSP: style-src sem
    'unsafe-inline' — pentest 2026-07 L6). A % (0–100, inteira, clampada no servidor —
    a UI nunca calcula dinheiro, regra 4) entra por um <style nonce> escopado a um id
    único, em vez de um atributo style= (que a CSP bloquearia). As classes de aparência
    (altura/cor/raio) vêm por $attributes.
--}}
@php
    $pct = max(0, min(100, (int) $pct));
    $barId = 'bar-'.\Illuminate\Support\Str::random(8);
@endphp
<div {{ $attributes }} id="{{ $barId }}"></div>
<style nonce="{{ $cspNonce ?? '' }}">#{{ $barId }}{width:{{ $pct }}%}</style>
