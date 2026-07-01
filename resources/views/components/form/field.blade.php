@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'placeholder' => null,
    'autocomplete' => null,
    'required' => false,
    'toggle' => false, // botão mostrar/ocultar (senha)
    'disabled' => false,
    'error' => null, // override p/ preview; senão usa o $errors bag
])

@php
    $error = $error ?? $errors->first($name);
    $hasError = filled($error);
    // Não repovoar campos de senha (boa prática).
    $inputValue = $type === 'password' ? null : old($name, $value);
    $descId = "{$name}-error";
@endphp

<div class="space-y-2">
    <div class="flex justify-between items-center">
        <label for="{{ $name }}" class="block font-body-sm text-body-sm text-on-surface-variant">{{ $label }}</label>
        @isset($action)
            {{ $action }}
        @endisset
    </div>

    <div class="relative">
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $inputValue }}"
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            @required($required)
            @disabled($disabled)
            @if ($hasError) aria-invalid="true" aria-describedby="{{ $descId }}" @endif
            @class([
                'input-field w-full rounded-lg px-4 py-3 font-body-md text-body-md text-on-surface',
                'pr-12' => $toggle,
                'border-argila focus:border-argila' => $hasError,
                'opacity-70 cursor-not-allowed' => $disabled,
            ])
            {{ $attributes->except('class') }}>

        @if ($toggle)
            <button type="button" data-password-toggle aria-pressed="false" aria-label="Mostrar senha"
                @disabled($disabled)
                class="absolute right-3 top-1/2 -translate-y-1/2 text-outline hover:text-on-surface transition-colors disabled:opacity-50"></button>
        @endif
    </div>

    @if ($hasError)
        <p id="{{ $descId }}" class="flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
            <x-icon name="alert" class="h-4 w-4 shrink-0" />
            <span>{{ $error }}</span>
        </p>
    @endif
</div>
