<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Operator Console') · Ingress</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #17202a; background: #f2f5f7; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        main { width: min(100%, 680px); }
        .brand { color: #1c4b43; font-size: .9rem; font-weight: 750; letter-spacing: .07em; text-transform: uppercase; }
        .card { margin-top: 14px; padding: clamp(24px, 6vw, 42px); border: 1px solid #dce4e7; border-radius: 18px; background: #fff; box-shadow: 0 12px 36px #18372f12; }
        h1 { margin: 0 0 12px; font-size: clamp(1.7rem, 5vw, 2.15rem); line-height: 1.15; }
        p { color: #4d5b63; line-height: 1.55; }
        label { display: block; margin: 20px 0 7px; font-weight: 650; }
        input { width: 100%; min-height: 48px; padding: 11px 13px; border: 1px solid #aebdc2; border-radius: 9px; font: inherit; }
        input:focus-visible, button:focus-visible { outline: 3px solid #5d9c8c; outline-offset: 2px; }
        button { min-height: 48px; padding: 11px 18px; border: 0; border-radius: 9px; background: #1c4b43; color: white; font: inherit; font-weight: 700; cursor: pointer; }
        form button { width: 100%; margin-top: 24px; }
        .error { padding: 12px 14px; border-radius: 8px; background: #fff1ef; color: #8c251d; }
        .status { margin-top: 24px; padding: 16px; border-radius: 10px; background: #eef5f2; }
        .status strong { display: block; margin-bottom: 5px; color: #1c4b43; }
        .topline { display: flex; align-items: center; justify-content: space-between; gap: 18px; }
        .topline form button { width: auto; margin: 0; background: #eff3f4; color: #24333a; }
        @media (max-width: 480px) { body { padding: 16px; } .topline { align-items: flex-start; flex-direction: column; } }
    </style>
</head>
<body>
<main>
    <div class="brand">Ingress · Event Node</div>
    @yield('content')
</main>
</body>
</html>
