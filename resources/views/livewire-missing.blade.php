<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Livewire required · Log Insight</title>
    @include('log-insight::partials.styles')
</head>
<body>
    <main class="li-container">
        <div class="li-card li-notice">
            <h1>The Log Insight dashboard needs Livewire</h1>
            <p>Install the optional package and reload this page:</p>
            <pre><code>composer require {{ $package }}</code></pre>
        </div>
    </main>
</body>
</html>
