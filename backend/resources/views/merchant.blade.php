<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>TAPZY Merchant</title>
    @vite(['resources/css/app.css', 'resources/js/merchant/main.js'])
</head>
<body class="min-h-screen bg-stone-50 text-stone-900 antialiased">
    <div id="merchant-app"></div>
</body>
</html>
