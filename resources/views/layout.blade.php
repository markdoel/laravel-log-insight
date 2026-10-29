<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Log Insight')</title>
    @include('log-insight::partials.styles')
    @livewireStyles
</head>
<body>
    <header class="li-header">
        <div class="li-container li-header-inner">
            <a class="li-brand" href="{{ route('log-insight.dashboard') }}">Log Insight</a>
            <span class="li-muted">{{ config('app.name') }}</span>
        </div>
    </header>

    <main class="li-container">
        @yield('content')
    </main>

    @livewireScripts
</body>
</html>
