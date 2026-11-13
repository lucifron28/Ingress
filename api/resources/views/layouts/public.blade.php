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

        body.public-events-theme {
            --ink: #0f172a;
            --muted: #475569;
            --line: #e2e8f0;
            --paper: #ffffff;
            --canvas: #f8fafc;
            --maroon: #800000;
            --gold: #c5a059;
            background: var(--canvas);
            color: var(--ink);
        }

        .public-events-theme .site-header {
            background: var(--maroon);
            border-bottom: 3px solid var(--gold);
            color: #ffffff;
        }

        .public-events-theme .site-nav,
        .public-events-theme main,
        .public-events-theme .site-footer {
            width: min(100% - 2rem, 74rem);
        }

        .public-events-theme .site-nav { min-height: 4.25rem; }

        .public-events-theme .brand {
            display: inline-flex;
            align-items: center;
            gap: .65rem;
            color: #ffffff;
            letter-spacing: .04em;
        }

        .public-events-theme .brand-mark {
            display: inline-flex;
            align-items: center;
            border-radius: .3rem;
            padding: .2rem .35rem;
            background: #ffffff;
        }

        .public-events-theme .brand-mark img { display: block; height: 1.4rem; width: auto; }

        .public-events-theme .site-nav > a:not(.brand),
        .public-events-theme .site-nav .nav-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 2.4rem;
            border: 1px solid rgb(255 255 255 / 42%);
            border-radius: .4rem;
            padding: .4rem .75rem;
            background: transparent;
            color: #ffffff;
            font-size: .9rem;
            font-weight: 650;
            line-height: 1.2;
            text-decoration: none;
        }

        .public-events-theme .site-nav .nav-action { font-family: inherit; cursor: pointer; }

        .public-events-theme .site-nav > a:not(.brand):hover,
        .public-events-theme .site-nav .nav-action:hover {
            background: rgb(255 255 255 / 12%);
        }

        .public-events-theme .site-nav a:focus-visible,
        .public-events-theme .site-nav button:focus-visible {
            outline: 3px solid #ffffff;
            outline-offset: 3px;
        }

        .public-events-theme main .button:focus-visible {
            outline: 3px solid var(--maroon);
            outline-offset: 3px;
        }

        .public-events-theme main {
            min-height: auto;
            padding: clamp(1.75rem, 4vw, 3rem) 0 3rem;
        }

        .events-index-page .events-intro { max-width: 52rem; margin-bottom: 1.75rem; }
        .public-events-theme .eyebrow { margin-bottom: .45rem; color: var(--maroon); }
        .events-index-page .events-intro h1 { margin-bottom: .6rem; font-size: clamp(2rem, 4vw, 3rem); }
        .events-index-page .lede { margin-bottom: 0; max-width: 48rem; }

        .events-index-page .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 30rem), 1fr));
            align-items: stretch;
            gap: 1.25rem;
        }

        .events-index-page .event-card {
            display: flex;
            min-width: 0;
            flex-direction: column;
            gap: 1.1rem;
            border: 1px solid var(--line);
            border-top: 3px solid var(--maroon);
            border-radius: .65rem;
            padding: 1.35rem;
            background: var(--paper);
            box-shadow: 0 2px 8px rgb(15 23 42 / 5%);
            transition: border-color 140ms ease, box-shadow 140ms ease;
        }

        .events-index-page .event-card:hover {
            border-color: #cbd5e1;
            border-top-color: var(--maroon);
            box-shadow: 0 8px 20px rgb(15 23 42 / 9%);
        }

        .events-index-page .event-card-content { display: grid; gap: 1rem; flex: 1; }
        .events-index-page .event-card h2 { margin: 0; font-size: clamp(1.25rem, 2vw, 1.5rem); line-height: 1.25; letter-spacing: -.02em; }

        .events-index-page .event-tag { width: fit-content; }
        .events-index-page .event-tag-cancelled { background: #fef2f2; color: #991b1b; }

        .events-index-page .event-facts { display: grid; gap: .7rem; }
        .events-index-page .event-fact { border-left: 2px solid #ead5d5; padding-left: .75rem; }
        .events-index-page .event-fact-label,
        .events-index-page .event-ticket-label { display: block; margin-bottom: .15rem; color: var(--muted); font-size: .78rem; font-weight: 700; letter-spacing: .035em; }
        .events-index-page .event-fact-value { display: flex; flex-wrap: wrap; align-items: baseline; gap: .25rem .4rem; margin: 0; color: var(--ink); font-size: .95rem; font-weight: 650; line-height: 1.45; }
        .events-index-page .event-description { margin: 0; color: var(--muted); }

        .events-index-page .event-ticket-list { display: grid; gap: .35rem; margin: 0; padding: 0; list-style: none; }
        .events-index-page .event-ticket-list li { display: flex; justify-content: space-between; gap: .75rem; color: var(--ink); font-size: .9rem; }
        .events-index-page .event-ticket-price { flex: 0 0 auto; color: var(--maroon); font-weight: 700; }

        .events-index-page .event-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            border-top: 1px solid var(--line);
            padding-top: 1rem;
        }

        .events-index-page .event-status { margin: 0; border-radius: 999px; padding: .35rem .65rem; font-size: .82rem; font-weight: 700; line-height: 1.35; }
        .events-index-page .event-status-open { background: #ecfdf5; color: #166534; }
        .events-index-page .event-status-unavailable { background: #f1f5f9; color: #475569; }

        .public-events-theme .button {
            flex: 0 0 auto;
            min-width: 8.5rem;
            border-radius: .4rem;
            background: var(--maroon);
            box-shadow: 0 2px 4px rgb(128 0 0 / 15%);
            transition: background-color 140ms ease, box-shadow 140ms ease;
        }

        .public-events-theme .button:hover { border-color: #5b0000; background: #5b0000; box-shadow: 0 4px 10px rgb(91 0 0 / 20%); }
        .public-events-theme .button:disabled,
        .public-events-theme .button[aria-disabled="true"] { opacity: .55; cursor: not-allowed; box-shadow: none; }
        .events-index-page .events-empty { border-top: 3px solid var(--gold); }

        @media (max-width: 36rem) {
            .public-events-theme .site-nav { min-height: 4rem; }
            .public-events-theme .brand { gap: .45rem; font-size: .9rem; }
            .public-events-theme .brand-mark img { height: 1.2rem; }
            .public-events-theme .site-nav > a:not(.brand),
            .public-events-theme .site-nav .nav-action { padding-inline: .55rem; font-size: .82rem; }
            .events-index-page .event-card { padding: 1rem; }
            .events-index-page .event-card-footer { align-items: stretch; flex-direction: column; }
            .events-index-page .button { width: 100%; }
        }

        @media (prefers-reduced-motion: reduce) {
            .events-index-page .event-card,
            .public-events-theme .button { transition: none; }
        }

        .event-details-page .event-breadcrumbs {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .5rem;
            margin: 0 0 1.25rem;
            color: var(--muted);
            font-size: .9rem;
        }

        .event-details-page .event-breadcrumbs a {
            color: var(--maroon);
            font-weight: 700;
            text-decoration: none;
        }

        .event-details-page .event-breadcrumbs a:hover { text-decoration: underline; }

        .event-details-page .event-detail-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(18rem, .8fr);
            align-items: start;
            gap: 1.25rem;
        }

        .event-details-page .event-detail-card,
        .event-details-page .ticket-panel {
            min-width: 0;
            border: 1px solid var(--line);
            border-radius: .65rem;
            padding: clamp(1.15rem, 2.5vw, 1.75rem);
            background: var(--paper);
            box-shadow: 0 2px 8px rgb(15 23 42 / 5%);
        }

        .event-details-page .event-detail-card {
            display: grid;
            gap: 1.5rem;
            border-top: 3px solid var(--maroon);
        }

        .event-details-page .ticket-panel {
            display: grid;
            gap: 1rem;
            border-top: 3px solid var(--gold);
        }

        .event-details-page .event-detail-heading { display: grid; gap: .6rem; }
        .event-details-page .event-detail-heading .eyebrow { margin: 0; }
        .event-details-page .event-detail-heading h1 { margin: 0; font-size: clamp(2rem, 4vw, 2.8rem); overflow-wrap: anywhere; }
        .event-details-page .event-detail-badges { display: flex; flex-wrap: wrap; gap: .5rem; }
        .event-details-page .event-cancelled-badge { background: #fef2f2; color: #991b1b; }

        .event-details-page .event-detail-facts {
            display: grid;
            gap: .85rem;
            margin: 0;
        }

        .event-details-page .event-detail-fact {
            min-width: 0;
            border-left: 2px solid #ead5d5;
            padding-left: .8rem;
        }

        .event-details-page .event-detail-fact dt,
        .event-details-page .ticket-category-eligibility,
        .event-details-page .ticket-category-availability {
            color: var(--muted);
            font-size: .82rem;
            font-weight: 700;
        }

        .event-details-page .event-detail-fact dd {
            display: flex;
            flex-wrap: wrap;
            gap: .2rem .4rem;
            margin: .2rem 0 0;
            color: var(--ink);
            font-weight: 650;
            overflow-wrap: anywhere;
        }

        .event-details-page .event-description {
            margin: 0;
            border-top: 1px solid var(--line);
            padding-top: 1.25rem;
            color: var(--muted);
            white-space: pre-line;
        }

        .event-details-page .event-eligibility-note {
            display: grid;
            gap: .25rem;
            margin: 0;
            border-left: 3px solid var(--maroon);
            border-radius: .35rem;
            padding: .9rem 1rem;
            background: #f8fafc;
            color: var(--muted);
        }

        .event-details-page .event-eligibility-note strong { color: var(--ink); }
        .event-details-page .ticket-panel-heading h2 { margin: 0 0 .35rem; font-size: 1.3rem; }
        .event-details-page .ticket-panel-heading .eyebrow { margin: 0 0 .35rem; }
        .event-details-page .ticket-panel-heading .meta { margin: 0; }
        .event-details-page .ticket-category-list { display: grid; gap: .8rem; }

        .event-details-page .ticket-category {
            display: grid;
            gap: .4rem;
            border-top: 1px solid var(--line);
            padding-top: .85rem;
        }

        .event-details-page .ticket-category-heading {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: space-between;
            gap: .4rem .8rem;
        }

        .event-details-page .ticket-category-heading h3 { margin: 0; font-size: 1rem; }
        .event-details-page .ticket-category-price { color: var(--maroon); font-weight: 750; white-space: nowrap; }
        .event-details-page .ticket-category-eligibility,
        .event-details-page .ticket-category-availability { margin: 0; font-weight: 500; }
        .event-details-page .ticket-category-availability strong { color: var(--ink); }
        .event-details-page .ticket-category-sold-out { color: #991b1b; font-weight: 700; }
        .event-details-page .ticket-payment-note { margin: .15rem 0 0; color: var(--muted); font-size: .85rem; }

        .event-details-page .registration-status {
            display: grid;
            gap: .2rem;
            border-left: 3px solid;
            border-radius: .35rem;
            padding: .8rem .9rem;
            font-size: .9rem;
        }

        .event-details-page .registration-status-open { border-color: #15803d; background: #ecfdf5; color: #166534; }
        .event-details-page .registration-status-unavailable { border-color: #b7791f; background: #fff8e8; color: #704f12; }
        .event-details-page .registration-status-cancelled { border-color: #b91c1c; background: #fef2f2; color: #991b1b; }
        .event-details-page .registration-status strong { color: inherit; }
        .event-details-page .ticket-panel .button { width: 100%; }

        @media (max-width: 48rem) {
            .event-details-page .event-detail-layout { grid-template-columns: minmax(0, 1fr); }
        }

        @media (max-width: 36rem) {
            .event-details-page .event-breadcrumbs { margin-bottom: 1rem; }
            .event-details-page .event-detail-layout { gap: 1rem; }
            .event-details-page .event-detail-card,
            .event-details-page .ticket-panel { padding: 1rem; }
        }

        @media print { body { background: #fff; } .site-header, .site-footer, .screen-only { display: none !important; } main { width: 100%; padding: 0; } .card { box-shadow: none; } }
    </style>
</head>
@php
    $isEventsListing = request()->routeIs('public.events.index');
    $isEventDetails = request()->routeIs('public.events.show');
@endphp
<body class="{{ $isEventsListing || $isEventDetails ? 'public-events-theme' : '' }}{{ $isEventsListing ? ' events-index-page' : '' }}{{ $isEventDetails ? ' event-details-page' : '' }}">
    <header class="site-header">
        <nav class="site-nav" aria-label="Main navigation">
            <a class="brand" href="{{ route('public.events.index') }}">
                @if ($isEventsListing || $isEventDetails)
                    <span class="brand-mark"><img src="{{ asset('images/ingress-mark.png') }}" alt=""></span>
                    <span>INGRESS</span>
                @else
                    INGRESS
                @endif
            </a>
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
