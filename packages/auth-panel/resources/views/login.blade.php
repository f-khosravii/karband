<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In - Authentication</title>
    <style>
        *, ::before, ::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #0f172a;
            color: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.5rem;
        }
        .card {
            background-color: #1e293b;
            border: 1px solid #334155;
            border-radius: 0.75rem;
            padding: 2.5rem;
            width: 100%;
            max-width: 26rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3), 0 8px 10px -6px rgba(0, 0, 0, 0.3);
        }
        .title { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; text-align: center; color: #ffffff; }
        .subtitle { font-size: 0.875rem; color: #94a3b8; margin-bottom: 2rem; text-align: center; }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; color: #cbd5e1; }
        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: #0f172a;
            border: 1px solid #334155;
            border-radius: 0.5rem;
            color: #f8fafc;
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        input:focus { border-color: #38bdf8; box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.25); }
        .btn {
            display: inline-block;
            width: 100%;
            padding: 0.75rem 1.5rem;
            background-color: #2563eb;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: background-color 0.2s;
            margin-top: 0.5rem;
        }
        .btn:hover { background-color: #1d4ed8; }
        .error-alert {
            background-color: rgba(239, 68, 68, 0.15);
            border: 1px solid #ef4444;
            color: #fca5a5;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            margin-bottom: 1.25rem;
        }
        .field-error { color: #f87171; font-size: 0.8rem; margin-top: 0.35rem; }
    </style>
</head>
<body>
    <main class="card">
        <h1 class="title">Sign In</h1>
        <p class="subtitle">Enter your credentials to access your account</p>

        @if ($errors->any())
            <div class="error-alert" role="alert">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route(config('auth-panel.route_name_prefix', 'auth.') . 'login.submit') }}">
            @csrf

            <div class="form-group">
                <label for="login_identifier">Email or Username</label>
                <input
                    type="text"
                    id="login_identifier"
                    name="login_identifier"
                    value="{{ old('login_identifier') }}"
                    required
                    autofocus
                    autocomplete="username"
                >
                @error('login_identifier')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                >
                @error('password')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <button type="submit" class="btn" id="login_submit_btn">
                Sign In
            </button>
        </form>
    </main>
</body>
</html>
