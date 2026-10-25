<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'DramaLoop') }}</title>

        {{-- Dark UI: the reference designs are all deep purple/black with pink accents. --}}
        <meta name="theme-color" content="#0B0410">

        @routes
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="bg-surface text-ink antialiased">
        @inertia
    </body>
</html>
