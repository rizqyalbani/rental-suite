<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#4f46e5">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Rental Suite') }}">
        <meta name="mobile-web-app-capable" content="yes">

        <title>{{ config('app.name', 'Rental Suite') }}</title>

        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="icon" href="/favicon.ico">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            :where(
                button,
                [role="button"],
                a[href],
                summary,
                [onclick],
                input[type="button"],
                input[type="submit"],
                input[type="reset"]
            ) {
                cursor: pointer;
            }

            :where(
                button:disabled,
                [role="button"][aria-disabled="true"],
                input[type="button"]:disabled,
                input[type="submit"]:disabled,
                input[type="reset"]:disabled
            ) {
                cursor: not-allowed;
            }
        </style>
    </head>
    <body class="font-sans text-base text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100">
            <div class="mb-6">
                <h1 class="text-3xl font-bold text-gray-800">Rental Suite</h1>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
