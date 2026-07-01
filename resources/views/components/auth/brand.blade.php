@props([
    'heading' => 'Agente Financeiro',
    'tagline' => 'Sua gestão financeira, com a clareza do papel.',
])

{{-- Cabeçalho das telas pré-login (sem navbar do app). O heading pode ser o
     wordmark (login) ou um título contextual (ex.: "Crie sua conta"). --}}
<div {{ $attributes->class('text-center mb-10') }}>
    <h1 class="font-headline-lg-mobile md:font-headline-lg text-headline-lg-mobile md:text-headline-lg text-primary mb-2">
        {{ $heading }}
    </h1>
    <p class="font-body-md text-body-md text-outline">{{ $tagline }}</p>
</div>
