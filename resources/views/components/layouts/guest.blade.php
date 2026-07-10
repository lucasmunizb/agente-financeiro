@props(['title' => 'Agente Financeiro', 'description' => 'Sua gestão financeira, com a clareza do papel.'])

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">

    {{-- Página pública: pode indexar. Telas autenticadas usam noindex. --}}
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:type" content="website">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>

<body class="min-h-screen flex items-center justify-center p-container-padding-mobile md:p-gutter">
    <div class="paper-texture" aria-hidden="true"></div>

    {{ $slot }}

    {{-- Toast (feedback efêmero pós-ação) — ex.: "Sua conta foi excluída." no login. --}}
    <x-ui.toast />

    @stack('scripts')
</body>

</html>
