<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Ingress' }} - MSEUF Event Management</title>

    <link rel="icon" type="image/png" href="{{ asset('images/ingress-favicon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <style>
        :root {
            --mseuf-maroon: #800000;
            --mseuf-maroon-dark: #5b0000;
            --mseuf-maroon-light: #991b1b;
            --mseuf-gold: #c5a059;
            --mseuf-gold-light: #dfc285;
            --surface-white: #ffffff;
            --surface-subtle: #f8fafc;
            --neutral-dark: #0f172a;
            --neutral-body: #334155;
            --neutral-muted: #64748b;
            --neutral-border: #e2e8f0;
            --state-success: #16a34a;
            --state-warning: #d97706;
            --state-error: #dc2626;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--neutral-body);
            background-color: var(--surface-subtle);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .mono {
            font-family: 'JetBrains Mono', monospace;
        }

        .header-nav {
            background-color: var(--mseuf-maroon);
            border-bottom: 3px solid var(--mseuf-gold);
            color: #ffffff;
        }

        .btn-primary {
            background-color: var(--mseuf-maroon);
            color: #ffffff;
            border: 1px solid var(--mseuf-maroon-dark);
            padding: 0.5rem 1rem;
            font-weight: 500;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.15s ease;
        }
        .btn-primary:hover {
            background-color: var(--mseuf-maroon-dark);
        }

        .btn-secondary {
            background-color: #ffffff;
            color: var(--neutral-dark);
            border: 1px solid var(--neutral-border);
            padding: 0.5rem 1rem;
            font-weight: 500;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.15s ease;
        }
        .btn-secondary:hover {
            background-color: #f1f5f9;
        }

        .btn-danger {
            background-color: var(--state-error);
            color: #ffffff;
            border: 1px solid #b91c1c;
            padding: 0.5rem 1rem;
            font-weight: 500;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-danger:hover {
            background-color: #b91c1c;
        }

        .card {
            background: var(--surface-white);
            border: 1px solid var(--neutral-border);
            border-radius: 6px;
            padding: 1.25rem;
        }

        .organizer-event-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .organizer-event-nav a {
            padding: 0.45rem 0.8rem;
            border: 1px solid var(--neutral-border);
            border-bottom: 2px solid var(--mseuf-gold);
            border-radius: 6px;
            background: var(--surface-white);
            color: var(--mseuf-maroon);
            font-weight: 600;
            text-decoration: none;
        }
        .organizer-event-nav a:hover {
            background: #fff8e8;
            border-color: var(--mseuf-gold);
            color: var(--mseuf-maroon-dark);
        }
        .organizer-event-nav a:focus-visible {
            outline: 3px solid var(--mseuf-gold);
            outline-offset: 2px;
        }
        .organizer-event-section {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .organizer-event-gates-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .organizer-event-audit { grid-column: 1 / -1; }
        .organizer-event-schedule {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .organizer-event-schedule dt,
        .organizer-event-metrics dt {
            color: var(--neutral-muted);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .organizer-event-schedule dd { margin-top: 0.3rem; }
        .organizer-event-description { grid-column: 1 / -1; }
        .organizer-event-attendance-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
        }
        .organizer-event-metrics {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.75rem;
            margin-top: 1.25rem;
        }
        .organizer-event-metrics > div {
            min-width: 0;
            padding: 0.85rem;
            border: 1px solid var(--neutral-border);
            border-radius: 6px;
            background: var(--surface-subtle);
        }
        .organizer-event-metrics dd {
            margin-top: 0.3rem;
            color: var(--mseuf-maroon);
            font-size: 1.35rem;
            font-weight: 700;
        }
        .event-anchor { scroll-margin-top: 1rem; }
        .event-preparation { margin-bottom: 1.5rem; border-top: 4px solid var(--mseuf-gold); }
        .event-readiness-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 15rem), 1fr));
            gap: 0.75rem;
            margin: 1rem 0;
            padding: 0;
            list-style: none;
        }
        .event-readiness-item {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.45rem;
            min-width: 0;
            padding: 0.85rem;
            border: 1px solid var(--neutral-border);
            border-radius: 6px;
            background: var(--surface-subtle);
        }
        .event-readiness-item p { color: var(--neutral-muted); font-size: 0.875rem; }
        .event-next-action {
            padding: 0.8rem 1rem;
            border-left: 4px solid var(--mseuf-gold);
            border-radius: 4px;
            background: #fff8e8;
            color: var(--neutral-dark);
        }
        .event-handoff { border-top: 3px solid var(--mseuf-maroon); }

        .event-create-shell { max-width: 800px; margin: 0 auto; }
        .event-create-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }
        .event-create-header h1 { color: var(--neutral-dark); font-size: 1.8rem; }
        .event-create-header p:not(.event-create-eyebrow) { margin-top: 0.35rem; color: var(--neutral-muted); }
        .event-create-eyebrow { color: var(--mseuf-maroon); font-size: 0.75rem; font-weight: 700; letter-spacing: 0.08em; }
        .event-create-errors {
            margin-bottom: 1rem;
            padding: 0.9rem 1rem;
            border: 1px solid #fecaca;
            border-left: 4px solid var(--state-error);
            border-radius: 5px;
            background: #fff7f7;
            color: #7f1d1d;
        }
        .event-create-errors ul { margin: 0.4rem 0 0 1.2rem; }
        .event-create-card { border-top: 4px solid var(--mseuf-maroon); }
        .event-create-progress {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.5rem;
            margin-bottom: 0.6rem;
            padding: 0;
            list-style: none;
        }
        .event-create-progress li {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 0;
            padding: 0.55rem;
            border: 1px solid var(--neutral-border);
            border-radius: 5px;
            background: var(--surface-subtle);
            color: var(--neutral-muted);
            font-size: 0.8rem;
        }
        .event-create-progress li > span {
            display: inline-grid;
            flex: 0 0 1.5rem;
            width: 1.5rem;
            height: 1.5rem;
            place-items: center;
            border-radius: 50%;
            background: #eee8e3;
            color: var(--neutral-body);
            font-size: 0.75rem;
            font-weight: 700;
        }
        .event-create-progress li[data-state="current"] { border-color: var(--mseuf-maroon); background: #fff8f6; color: var(--neutral-dark); }
        .event-create-progress li[data-state="current"] > span,
        .event-create-progress li[data-state="complete"] > span { background: var(--mseuf-maroon); color: #ffffff; }
        .event-create-step-status { margin-bottom: 1.25rem; color: var(--mseuf-maroon); font-size: 0.8rem; font-weight: 700; }
        .event-create-step[hidden] { display: none; }
        .event-create-step h2 { color: var(--neutral-dark); font-size: 1.35rem; }
        .event-create-intro { margin: 0.25rem 0 1.25rem; color: var(--neutral-muted); }
        .event-create-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 1rem; }
        .event-field-help { margin-top: 0.3rem; color: var(--neutral-muted); font-size: 0.8rem; }
        .event-field-error { margin-top: 0.3rem; color: #991b1b; font-size: 0.82rem; }
        .event-optional { margin-left: 0.25rem; color: var(--neutral-muted); font-size: 0.75rem; font-weight: 400; }
        .event-create-choice { margin: 0 0 0.85rem; padding: 0.8rem; border: 1px solid var(--neutral-border); border-radius: 5px; background: #fffdfa; }
        .event-create-choice label { display: flex; align-items: flex-start; gap: 0.65rem; color: var(--neutral-dark); cursor: pointer; }
        .event-create-choice input { margin-top: 0.22rem; accent-color: var(--mseuf-maroon); }
        .event-create-choice small { display: block; margin-top: 0.2rem; color: var(--neutral-muted); font-size: 0.8rem; }
        .event-create-review { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem; margin: 1rem 0 1.25rem; }
        .event-create-review > div { min-width: 0; padding: 0.7rem; border: 1px solid var(--neutral-border); border-radius: 5px; background: var(--surface-subtle); }
        .event-create-review dt { color: var(--neutral-muted); font-size: 0.72rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
        .event-create-review dd { margin-top: 0.25rem; color: var(--neutral-dark); font-size: 0.9rem; overflow-wrap: anywhere; }
        .event-create-note { padding: 0.85rem 1rem; border-left: 4px solid var(--mseuf-gold); border-radius: 4px; background: #fff8e8; color: var(--neutral-dark); }
        .event-create-note p { margin-top: 0.35rem; color: var(--neutral-body); font-size: 0.85rem; }
        .event-create-actions { display: flex; justify-content: flex-end; margin-top: 1rem; }
        .event-create-navigation { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--neutral-border); }
        .event-create-navigation[hidden] { display: none; }
        .event-create-navigation > span { color: var(--neutral-muted); font-size: 0.8rem; }
        .event-create-wizard-active [data-event-step] > h2:focus { outline: none; }
        .event-create-wizard-active :focus-visible { outline: 3px solid var(--mseuf-gold); outline-offset: 2px; }
        .event-create-progress li[data-state="complete"] { color: var(--neutral-body); }

        .event-progress-guide { margin-bottom: 1.25rem; border-top: 4px solid var(--mseuf-gold); }
        .event-progress-guide > p { margin-top: 0.45rem; color: var(--neutral-muted); font-size: 0.875rem; }
        .event-progress-list { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.65rem; margin: 1rem 0; padding: 0; list-style: none; }
        .event-progress-list li { min-width: 0; padding: 0.7rem; border: 1px solid var(--neutral-border); border-radius: 5px; background: var(--surface-subtle); }
        .event-progress-list li[data-state="current"] { border-color: var(--mseuf-maroon); background: #fff8f6; }
        .event-progress-list li[data-state="complete"] { border-top: 3px solid var(--state-success); }
        .event-progress-list strong { display: block; color: var(--neutral-dark); }
        .event-progress-list small { display: block; margin-top: 0.2rem; color: var(--neutral-muted); font-size: 0.78rem; }
        .event-progress-guide a { color: var(--mseuf-maroon); font-weight: 600; }
        .event-progress-guide .event-next-action { margin-top: 0.75rem; }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-error { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #e0f2fe; color: #075985; }
        .badge-neutral { background: #f1f5f9; color: #475569; }

        .table-container {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--neutral-border);
            border-radius: 6px;
            background: #ffffff;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.875rem;
        }
        th {
            background-color: #f8fafc;
            color: var(--neutral-muted);
            font-weight: 600;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--neutral-border);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--neutral-border);
            color: var(--neutral-dark);
        }
        tr:last-child td {
            border-bottom: none;
        }
        tr:hover td {
            background-color: #f8fafc;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-label {
            display: block;
            margin-bottom: 0.375rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--neutral-dark);
        }
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--neutral-border);
            border-radius: 4px;
            font-size: 0.875rem;
            color: var(--neutral-dark);
            background: #ffffff;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--mseuf-maroon);
            box-shadow: 0 0 0 2px rgba(128, 0, 0, 0.15);
        }

        @media (max-width: 640px) {
            .organizer-event-nav a { flex: 1 1 auto; text-align: center; }
            .organizer-event-gates-grid,
            .organizer-event-schedule,
            .organizer-event-metrics,
            .event-create-grid,
            .event-create-review { grid-template-columns: 1fr; }
            .event-create-progress,
            .event-progress-list { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .organizer-event-description,
            .organizer-event-audit { grid-column: auto; }
            .organizer-event-attendance-header { flex-direction: column; }
            .event-create-header { flex-direction: column; }
            .event-create-navigation > span { display: none; }
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    @auth
    <header class="header-nav">
        <div style="max-width: 1200px; margin: 0 auto; padding: 0.75rem 1.5rem; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 1.25rem;">
                <a href="{{ route('organizer.dashboard') }}" style="color: #ffffff; text-decoration: none; font-weight: 700; font-size: 1.25rem; letter-spacing: -0.025em; display: flex; align-items: center; gap: 0.5rem;">
                    {{-- The mark is maroon, so it needs a light plate to read on the maroon header. --}}
                    <span style="background: #ffffff; padding: 0.2rem 0.4rem; border-radius: 4px; display: inline-flex; align-items: center;">
                        <img src="{{ asset('images/ingress-mark.png') }}" alt="" style="display: block; height: 22px; width: auto;">
                    </span>
                    INGRESS
                </a>
                <nav style="display: flex; gap: 1rem; font-size: 0.875rem;">
                    <a href="{{ route('organizer.dashboard') }}" style="color: {{ request()->routeIs('organizer.dashboard') ? '#ffffff' : '#fca5a5' }}; text-decoration: none; font-weight: {{ request()->routeIs('organizer.dashboard') ? '600' : '400' }};">Dashboard</a>
                    <a href="{{ route('organizer.events.index') }}" style="color: {{ request()->routeIs('organizer.events.*') ? '#ffffff' : '#fca5a5' }}; text-decoration: none; font-weight: {{ request()->routeIs('organizer.events.*') ? '600' : '400' }};">Events</a>
                    @if (Auth::user()->isAdmin() && config('ingress.mode') === 'cloud')
                        <a href="{{ route('organizer.venues.index') }}" style="color: {{ request()->routeIs('organizer.venues.*') ? '#ffffff' : '#fca5a5' }}; text-decoration: none; font-weight: {{ request()->routeIs('organizer.venues.*') ? '600' : '400' }};">Venues</a>
                        <a href="{{ route('admin.settings.student-access') }}" style="color: {{ request()->routeIs('admin.settings.*') ? '#ffffff' : '#fca5a5' }}; text-decoration: none; font-weight: {{ request()->routeIs('admin.settings.*') ? '600' : '400' }};">Student access</a>
                    @endif
                </nav>
            </div>
            <div style="display: flex; align-items: center; gap: 1rem; font-size: 0.875rem;">
                <span style="color: #fecaca;">{{ Auth::user()->name }} ({{ ucfirst(Auth::user()->role) }})</span>
                <form method="POST" action="{{ route('logout') }}" style="display: inline;">
                    @csrf
                    <button type="submit" style="background: none; border: 1px solid rgba(255,255,255,0.3); color: #ffffff; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; cursor: pointer;">Sign Out</button>
                </form>
            </div>
        </div>
    </header>
    @endauth

    <main style="max-width: 1200px; margin: 0 auto; padding: 2rem 1.5rem; width: 100%; flex: 1;">
        @if (session('success'))
            <div style="background-color: #ecfdf5; border-left: 4px solid var(--state-success); color: #065f46; padding: 1rem; margin-bottom: 1.5rem; border-radius: 4px; font-size: 0.875rem;">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div style="background-color: #fef2f2; border-left: 4px solid var(--state-error); color: #991b1b; padding: 1rem; margin-bottom: 1.5rem; border-radius: 4px; font-size: 0.875rem;">
                {{ session('error') }}
            </div>
        @endif

        {{ $slot ?? '' }}
        @yield('content')
    </main>

    <footer style="background: #ffffff; border-top: 1px solid var(--neutral-border); padding: 1.25rem 1.5rem; font-size: 0.75rem; color: var(--neutral-muted); text-align: center; margin-top: auto;">
        <div style="max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center;">
            <div>Manuel S. Enverga University Foundation &bull; Lucena City, Quezon</div>
            <div>
                @if (config('ingress.mode') === 'node')
                    Ingress Event Node &bull; LAN Mode Active
                @elseif (config('ingress.mode') === 'cloud')
                    Ingress Cloud Event Management
                @else
                    Ingress Local Runtime
                @endif
            </div>
        </div>
    </footer>
</body>
</html>
