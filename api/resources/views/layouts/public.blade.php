<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Ingress events' }}</title>
    <style>
        :root { color-scheme: light; --ink: #241d1d; --muted: #6d6260; --line: #e7dfda; --paper: #fff; --canvas: #f7f4f1; --maroon: #741d2b; --gold: #bb9553; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--canvas); color: var(--ink); font: 16px/1.55 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        a { color: var(--maroon); }
        .site-header { background: #fff; border-bottom: 1px solid var(--line); }
        .site-nav, main, .site-footer { width: min(100% - 2rem, 70rem); margin: 0 auto; }
        .site-nav { min-height: 4rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        .brand { color: var(--maroon); font-weight: 800; letter-spacing: .09em; text-decoration: none; }
        .site-nav a:last-child { font-size: .9rem; }
        .site-nav .nav-action { border: 0; padding: .25rem; background: transparent; color: var(--maroon); font: inherit; cursor: pointer; text-decoration: underline; }
        main { min-height: calc(100vh - 8rem); padding: 2.25rem 0 3rem; }
        .site-footer { border-top: 1px solid var(--line); padding: 1.25rem 0 2rem; color: var(--muted); font-size: .8rem; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { line-height: 1.15; letter-spacing: -.03em; font-size: clamp(1.8rem, 5vw, 3rem); }
        .lede { color: var(--muted); max-width: 48rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 18rem), 1fr)); gap: 1rem; }
        .card { background: var(--paper); border: 1px solid var(--line); border-radius: .8rem; padding: 1.25rem; box-shadow: 0 1px 2px #241d1d0a; }
        .eyebrow { color: var(--muted); font-size: .77rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .button { display: inline-flex; align-items: center; justify-content: center; min-height: 2.75rem; border: 1px solid var(--maroon); border-radius: .45rem; padding: .6rem 1rem; background: var(--maroon); color: #fff; font-weight: 700; text-decoration: none; cursor: pointer; }
        .button.secondary { border-color: var(--line); background: #fff; color: var(--ink); }
        .button:disabled { opacity: .5; cursor: not-allowed; }
        .tag { display: inline-block; border-radius: 999px; padding: .2rem .65rem; background: #f0e7e4; color: var(--maroon); font-size: .75rem; font-weight: 700; }
        .notice { margin: 1rem 0; border-radius: .45rem; padding: .85rem 1rem; background: #fff5de; color: #654b1c; }
        .error { margin: 1rem 0; border: 1px solid #edcaca; border-radius: .45rem; padding: .85rem 1rem; background: #fff5f5; color: #8d1c1c; }
        .field { display: grid; gap: .35rem; margin: 1rem 0; }
        .field label { font-size: .9rem; font-weight: 700; }
        .field input, .field select { width: 100%; border: 1px solid #cfc6c0; border-radius: .4rem; padding: .7rem .75rem; background: #fff; color: var(--ink); font: inherit; }
        .meta { color: var(--muted); font-size: .93rem; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        .stack { display: grid; gap: 1rem; }
        .pass { max-width: 34rem; margin: 0 auto; text-align: center; }
        .pass-qr { width: min(100%, 22rem); margin: 1rem auto; }
        .pass-qr svg { display: block; width: 100%; height: auto; }
        @media (max-width: 36rem) { main { padding-top: 1.5rem; } .card { padding: 1rem; } }
        @media print { body { background: #fff; } .site-header, .site-footer, .screen-only { display: none !important; } main { width: 100%; padding: 0; } .card { box-shadow: none; } }
    </style>
</head>
<body>
    <header class="site-header">
        <nav class="site-nav" aria-label="Main navigation">
            <a class="brand" href="{{ route('public.events.index') }}">INGRESS</a>
            @auth
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-action" type="submit">Sign out</button></form>
            @else
                <a href="{{ route('student.auth') }}">Student sign in</a>
            @endauth
        </nav>
    </header>
    <main>
        @if (session('success')) <div class="notice" role="status">{{ session('success') }}</div> @endif
        @if (session('info')) <div class="notice" role="status">{{ session('info') }}</div> @endif
        @yield('content')
    </main>
    <footer class="site-footer">Ingress event registration · Manuel S. Enverga University Foundation</footer>
</body>
</html>
