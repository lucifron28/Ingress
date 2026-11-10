<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Student sign in · Ingress</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #17202b; background: #f5f6f8; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; }
        main { width: min(100%, 440px); }
        .brand { margin: 0 0 18px; color: #800000; font-size: .86rem; font-weight: 750; letter-spacing: .12em; text-align: center; text-transform: uppercase; }
        .card { padding: clamp(22px, 6vw, 36px); border: 1px solid #dfe3e8; border-radius: 14px; background: #fff; box-shadow: 0 18px 50px rgb(24 32 43 / 8%); }
        h1 { margin: 0 0 8px; color: #171b22; font-size: 1.6rem; line-height: 1.2; }
        .intro { margin: 0 0 24px; color: #596575; font-size: .95rem; }
        .tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 22px; padding: 4px; border-radius: 9px; background: #f1f2f4; }
        .tabs button { padding: 10px 8px; border: 0; border-radius: 6px; color: #4b5563; background: transparent; font: inherit; font-size: .9rem; font-weight: 650; cursor: pointer; }
        .tabs button[aria-selected="true"] { color: #800000; background: #fff; box-shadow: 0 1px 3px rgb(16 24 40 / 12%); }
        form { display: grid; gap: 15px; }
        label { display: block; margin-bottom: 6px; color: #273243; font-size: .88rem; font-weight: 650; }
        input { width: 100%; min-height: 44px; padding: 10px 12px; border: 1px solid #cbd2da; border-radius: 7px; color: #17202b; background: #fff; font: inherit; }
        input:focus { border-color: #800000; outline: 3px solid rgb(128 0 0 / 12%); }
        button.primary { min-height: 46px; margin-top: 4px; padding: 10px 14px; border: 1px solid #670000; border-radius: 7px; color: #fff; background: #800000; font: inherit; font-weight: 700; cursor: pointer; }
        button.primary:hover { background: #690000; }
        button:disabled { cursor: wait; opacity: .65; }
        .text-button { display: block; width: 100%; margin-top: 12px; padding: 6px; border: 0; color: #800000; background: transparent; font: inherit; font-size: .88rem; font-weight: 650; cursor: pointer; }
        .notice { margin: 0 0 16px; padding: 12px 14px; border: 1px solid #cad7e4; border-radius: 7px; color: #334155; background: #f4f8fc; font-size: .9rem; }
        .notice.error { border-color: #fecaca; color: #991b1b; background: #fef2f2; }
        .notice.success { border-color: #bbf7d0; color: #166534; background: #f0fdf4; }
        .notice[hidden], [hidden] { display: none !important; }
        .foot { margin: 20px 0 0; color: #667085; font-size: .82rem; line-height: 1.6; text-align: center; }
        .foot a { color: #800000; font-weight: 650; }
        .privacy { margin: 17px 0 0; color: #667085; font-size: .78rem; line-height: 1.5; }
        @media (prefers-reduced-motion: no-preference) { button { transition: background-color .15s ease, opacity .15s ease; } }
    </style>
</head>
<body>
    <main data-student-auth
        data-firebase-config="{{ json_encode($firebaseConfig, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) }}"
        data-email-domain="{{ $studentEmailDomain }}"
        data-configured="{{ $firebaseConfigured ? 'true' : 'false' }}">
        <p class="brand">Ingress · MSEUF</p>
        <section class="card" aria-labelledby="page-title">
            <h1 id="page-title">Student account</h1>
            <p class="intro">Use your {{ '@'.$studentEmailDomain }} address to create or access your account.</p>

            @unless ($firebaseConfigured)
                <p class="notice error" role="alert">Student sign-in is not configured yet. Ask the Ingress administrator to add the Firebase web settings.</p>
            @endunless

            <noscript><p class="notice error" role="alert">JavaScript is required for secure Firebase student sign-in.</p></noscript>

            <p data-message class="notice" role="status" aria-live="polite" hidden></p>

            <div class="tabs" role="tablist" aria-label="Student account options" data-tabs>
                <button type="button" role="tab" id="tab-sign-in" aria-controls="panel-sign-in" aria-selected="true" data-show="sign-in">Sign in</button>
                <button type="button" role="tab" id="tab-create" aria-controls="panel-create" aria-selected="false" data-show="create">Create account</button>
            </div>

            <form id="panel-sign-in" method="post" action="{{ route('student.auth') }}" role="tabpanel" aria-labelledby="tab-sign-in" data-panel="sign-in">
                <div>
                    <label for="sign-in-email">Student email</label>
                    <input id="sign-in-email" name="email" type="email" autocomplete="username" inputmode="email" required maxlength="255">
                </div>
                <div>
                    <label for="sign-in-password">Password</label>
                    <input id="sign-in-password" name="password" type="password" autocomplete="current-password" required maxlength="128">
                </div>
                <button class="primary" type="submit">Sign in with your student email</button>
                <button class="text-button" type="button" data-show="reset">Forgot your password?</button>
            </form>

            <form id="panel-create" method="post" action="{{ route('student.auth') }}" role="tabpanel" aria-labelledby="tab-create" data-panel="create" hidden>
                <div>
                    <label for="student-name">Full name</label>
                    <input id="student-name" name="name" type="text" autocomplete="name" required maxlength="100">
                </div>
                <div>
                    <label for="create-email">Student email</label>
                    <input id="create-email" name="email" type="email" autocomplete="email" inputmode="email" required maxlength="255">
                </div>
                <div>
                    <label for="create-password">Password</label>
                    <input id="create-password" name="password" type="password" autocomplete="new-password" minlength="12" required maxlength="128">
                </div>
                <div>
                    <label for="confirm-password">Confirm password</label>
                    <input id="confirm-password" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required maxlength="128">
                </div>
                <button class="primary" type="submit">Create your student account</button>
            </form>

            <form id="panel-reset" method="post" action="{{ route('student.auth') }}" role="tabpanel" aria-label="Reset password" data-panel="reset" hidden>
                <div>
                    <label for="reset-email">Student email</label>
                    <input id="reset-email" name="email" type="email" autocomplete="email" inputmode="email" required maxlength="255">
                </div>
                <button class="primary" type="submit">Send password reset email</button>
                <button class="text-button" type="button" data-show="sign-in">Back to sign in</button>
            </form>

            <section data-verification hidden>
                <p class="notice success">Check your student inbox for a Firebase verification email. Open its link, then return here to finish signing in.</p>
                <button class="primary" type="button" data-resend>Resend verification email</button>
                <button class="text-button" type="button" data-continue>I've verified my email</button>
            </section>

            <section data-continue-panel hidden>
                <p class="notice success">Your student email is verified. Continue to Ingress.</p>
                <button class="primary" type="button" data-continue>Continue to Ingress</button>
            </section>

            <p class="privacy">Email verification confirms access to this mailbox. It does not confirm current enrollment. Your student number is not requested here.</p>
        </section>
        <p class="foot"><a href="{{ route('login') }}">Organizer sign in</a></p>
    </main>
    @vite('resources/js/student-auth.js')
</body>
</html>
