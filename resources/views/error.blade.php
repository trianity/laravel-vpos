<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>Payment error</title></head>
<body><main><h1>Payment could not be started</h1><p>{{ session('status') }}</p></main></body>
</html>
