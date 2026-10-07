<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Directory') — Karband Platform</title>
    <style>
        :root {
            --bg-page: #0f172a;
            --bg-card: #1e293b;
            --bg-card-hover: #334155;
            --border-color: #334155;
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --accent-blue: #38bdf8;
            --accent-green: #34d399;
            --accent-amber: #fbbf24;
            --accent-rose: #fb7185;
            --badge-active-bg: rgba(52, 211, 153, 0.15);
            --badge-active-text: #34d399;
            --badge-inactive-bg: rgba(251, 113, 133, 0.15);
            --badge-inactive-text: #fb7185;
            --badge-role-bg: rgba(56, 189, 248, 0.15);
            --badge-role-text: #38bdf8;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            background-color: var(--bg-page);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            background-color: var(--bg-card);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .brand {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .brand span {
            color: var(--accent-blue);
        }

        nav {
            display: flex;
            gap: 1.5rem;
            align-items: center;
        }

        nav a {
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: color 0.15s;
        }

        nav a:hover, nav a.active {
            color: var(--accent-blue);
        }

        main {
            flex: 1;
            padding: 2rem;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
        }

        .page-desc {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin-top: 0.25rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 500;
            font-size: 0.9rem;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s;
        }

        .btn-primary {
            background-color: var(--accent-blue);
            color: #0f172a;
        }

        .btn-primary:hover {
            opacity: 0.9;
        }

        .btn-secondary {
            background-color: var(--bg-card);
            border-color: var(--border-color);
            color: var(--text-primary);
        }

        .btn-secondary:hover {
            background-color: var(--bg-card-hover);
        }

        .btn-danger {
            background-color: rgba(251, 113, 133, 0.15);
            border-color: var(--accent-rose);
            color: var(--accent-rose);
        }

        .btn-danger:hover {
            background-color: var(--accent-rose);
            color: #0f172a;
        }

        .btn-success {
            background-color: rgba(52, 211, 153, 0.15);
            border-color: var(--accent-green);
            color: var(--accent-green);
        }

        .btn-success:hover {
            background-color: var(--accent-green);
            color: #0f172a;
        }

        .btn-sm {
            padding: 0.25rem 0.65rem;
            font-size: 0.8rem;
        }

        .card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        th {
            text-align: left;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-muted);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-active {
            background-color: var(--badge-active-bg);
            color: var(--badge-active-text);
        }

        .badge-inactive {
            background-color: var(--badge-inactive-bg);
            color: var(--badge-inactive-text);
        }

        .badge-role {
            background-color: var(--badge-role-bg);
            color: var(--badge-role-text);
            margin-right: 0.25rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .form-control {
            width: 100%;
            padding: 0.625rem 0.875rem;
            background-color: var(--bg-page);
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            color: var(--text-primary);
            font-size: 0.95rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-blue);
        }

        .alert {
            padding: 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        .alert-success {
            background-color: rgba(52, 211, 153, 0.15);
            border: 1px solid var(--accent-green);
            color: var(--accent-green);
        }

        .alert-danger {
            background-color: rgba(251, 113, 133, 0.15);
            border: 1px solid var(--accent-rose);
            color: var(--accent-rose);
        }

        .filter-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <header>
        <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.index') }}" class="brand">
            <span>Karband</span> Directory
        </a>
        <nav>
            <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'organizations.index') }}">Organizations</a>
            <a href="{{ route(config('directory-panel.route_name_prefix', 'directory.') . 'users.index') }}">Users</a>
        </nav>
    </header>

    <main>
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger">
                <ul style="padding-left: 1.25rem;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
