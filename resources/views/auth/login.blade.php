<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login · TRIDENT</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
    <main class="flex min-h-full flex-col justify-center px-6 py-12 lg:px-8">
        <div class="sm:mx-auto sm:w-full sm:max-w-sm">
            <div class="flex justify-center">
                <img src="/logo.png?v=2" alt="TRIDENT — Control &amp; Dispatch Center" class="h-32 w-auto object-contain" />
            </div>
            <h1 class="mt-4 text-center text-xl font-semibold text-gray-900">Sign in to your account</h1>
        </div>

        <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-sm">
            @if ($errors->any())
                <div role="alert" class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-700">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            @if (session('status'))
                <div role="status" class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="identity" class="block text-sm font-medium text-gray-700">Email, username or phone</label>
                    <input id="identity" name="identity" type="text" autocomplete="username" required autofocus
                        value="{{ old('identity') }}"
                        class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        placeholder="you@example.com">
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                        <a href="{{ route('password.request') }}" class="text-sm font-medium text-blue-600 hover:text-blue-500 hover:underline">Forgot password?</a>
                    </div>
                    <div class="relative mt-1">
                        <input id="password" name="password" type="password" autocomplete="current-password" required
                            class="block w-full rounded-lg border border-gray-300 px-3 py-2.5 pr-11 text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <button type="button" id="togglePassword" aria-label="Show password" aria-pressed="false"
                            class="absolute inset-y-0 right-0 flex items-center justify-center px-3 text-gray-400 hover:text-gray-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                            <svg id="eyeShow" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg id="eyeHide" viewBox="0 0 24 24" class="hidden h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>
                        </button>
                    </div>
                </div>

                <div class="flex items-center">
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input name="remember" type="checkbox" class="h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        Remember me
                    </label>
                </div>

                <button type="submit"
                    class="flex w-full justify-center rounded-lg bg-blue-600 px-3 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 transition-colors">
                    Sign in
                </button>
            </form>
        </div>
    </main>

    <script>
        (function () {
            var btn = document.getElementById('togglePassword');
            var input = document.getElementById('password');
            var eyeShow = document.getElementById('eyeShow');
            var eyeHide = document.getElementById('eyeHide');
            if (!btn || !input) return;
            btn.addEventListener('click', function () {
                var reveal = input.type === 'password';
                input.type = reveal ? 'text' : 'password';
                btn.setAttribute('aria-pressed', reveal ? 'true' : 'false');
                btn.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
                eyeShow.classList.toggle('hidden', reveal);
                eyeHide.classList.toggle('hidden', !reveal);
            });
        })();
    </script>
</body>
</html>
