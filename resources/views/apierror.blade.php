<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>Payment service unavailable</title></head>
<body><main><h1>Payment service unavailable</h1><p>{{ session('status') }}</p></main></body>
</html>
