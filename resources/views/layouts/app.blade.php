<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
   
       <!--qUESTI sono per la pwa -->
      <link rel="manifest" href="/manifest.webmanifest">
      <meta name="theme-color" content="#4f46e5">
      <link rel="apple-touch-icon" href="/icons/icon-192.png">

    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
         <main class="pb-24">
             {{ $slot }}
         </main>

        </div>


        <nav class="fixed bottom-0 inset-x-0 z-50 border-t bg-white/95 backdrop-blur sm:hidden">
    <div class="max-w-7xl mx-auto px-4">
        <div class="grid grid-cols-5 text-center text-xs">
            <a href="{{ route('dashboard') }}" class="py-3 {{ request()->routeIs('dashboard') ? 'text-indigo-600 font-semibold' : 'text-gray-600' }}">
                Home
            </a>
            <a href="{{ route('calendar') }}" class="py-3 {{ request()->routeIs('calendar') ? 'text-indigo-600 font-semibold' : 'text-gray-600' }}">
                Calendario
            </a>
            <a href="{{ route('posts') }}" class="py-3 {{ request()->routeIs('posts') ? 'text-indigo-600 font-semibold' : 'text-gray-600' }}">
                Post
            </a>
            <a href="{{ route('notifications') }}" class="py-3 {{ request()->routeIs('notifications') ? 'text-indigo-600 font-semibold' : 'text-gray-600' }}">
                Notifiche
            </a>
            <a href="{{ route('settings') }}" class="py-3 {{ request()->routeIs('settings') ? 'text-indigo-600 font-semibold' : 'text-gray-600' }}">
                Impostazioni
            </a>
        </div>
    </div>
</nav>


<script>
  if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => {
      navigator.serviceWorker.register("/sw.js").catch(console.error);
    });
  }
</script>


    </body>
</html>
