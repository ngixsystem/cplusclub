<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
        try { document.documentElement.dataset.theme = localStorage.getItem('cclub-theme') === 'light' ? 'light' : 'dark'; }
        catch { document.documentElement.dataset.theme = 'dark'; }
    </script>
    @vite(['resources/js/app.ts'])
    @inertiaHead
</head>
<body>@inertia</body>
</html>
