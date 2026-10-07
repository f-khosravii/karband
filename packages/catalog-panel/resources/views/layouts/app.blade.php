<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Service Catalog') - Karband</title>
    <style>
        :root {
            --bg: #0f172a;
            --surface: #1e293b;
            --surface-hover: #334155;
            --border: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --success: #10b981;
            --success-bg: rgba(16, 185, 129, 0.15);
            --warning: #f59e0b;
            --warning-bg: rgba(245, 158, 11, 0.15);
            --danger: #ef4444;
            --danger-bg: rgba(239, 68, 68, 0.15);
            --badge-inactive: #64748b;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-main);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .container {
            max-width: 1200px;
            width: 100%;
            margin: 2rem auto;
            padding: 0 1.5rem;
            flex: 1;
        }

        .card {
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
            border-bottom: 1px solid var(--border);
            padding-bottom: 1rem;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 0.5rem;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease-in-out;
            gap: 0.375rem;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-secondary {
            background-color: var(--surface-hover);
            color: var(--text-main);
            border-color: var(--border);
        }
        .btn-secondary:hover {
            background-color: var(--border);
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-danger {
            background-color: var(--danger);
            color: white;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .badge-active {
            background-color: var(--success-bg);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .badge-inactive {
            background-color: rgba(100, 116, 139, 0.2);
            color: var(--badge-inactive);
            border: 1px solid var(--badge-inactive);
        }

        .badge-archived {
            background-color: var(--warning-bg);
            color: var(--warning);
            border: 1px solid var(--warning);
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th, td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid var(--border);
        }

        th {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            background-color: rgba(15, 23, 42, 0.4);
        }

        tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-main);
        }

        .form-control {
            width: 100%;
            padding: 0.625rem 0.875rem;
            background-color: var(--bg);
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            color: var(--text-main);
            font-size: 0.875rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }

        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }

        .alert-success {
            background-color: var(--success-bg);
            border: 1px solid var(--success);
            color: #34d399;
        }

        .alert-danger {
            background-color: var(--danger-bg);
            border: 1px solid var(--danger);
            color: #f87171;
        }

        .alert-warning {
            background-color: var(--warning-bg);
            border: 1px solid var(--warning);
            color: #fbbf24;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .meta-list dt {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-top: 0.75rem;
        }
        .meta-list dd {
            font-size: 0.95rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .code-pill {
            font-family: monospace;
            background-color: rgba(15, 23, 42, 0.6);
            padding: 0.2rem 0.4rem;
            border-radius: 0.25rem;
            border: 1px solid var(--border);
        }
    </style>
</head>
<body>
    <header>
        <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'index') }}" class="brand">
            <span>Karband</span>
            <span style="color: var(--text-muted); font-weight: normal;">|</span>
            <span>Service Catalog</span>
        </a>
        <nav>
            <a href="{{ route(config('catalog-panel.route_name_prefix', 'catalog.') . 'index') }}" class="btn btn-secondary">Services List</a>
        </nav>
    </header>

    <main class="container">
        @if (session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul style="padding-left: 1.25rem;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
