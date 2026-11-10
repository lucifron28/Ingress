<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Event manager invitation | {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 2rem 1rem; background: #f7f7f8; color: #202124; }
        main { max-width: 34rem; margin: 3rem auto; padding: 2rem; background: white; border: 1px solid #ddd; border-radius: .5rem; }
        label { display: block; margin-top: 1rem; font-weight: 600; }
        input { box-sizing: border-box; width: 100%; padding: .7rem; margin-top: .3rem; }
        button { margin-top: 1.25rem; padding: .75rem 1rem; cursor: pointer; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
<main>
    <h1>Event manager invitation</h1>
    <p id="status" role="status">Checking your invitation…</p>
    <section id="invitation" hidden>
        <p>You have been invited to manage <strong id="event-title"></strong>.</p>
        <p>This access applies to this event only.</p>
        <p id="invite-email"></p>
        <p id="sign-in" hidden>
            <span id="sign-in-message"></span>
            <a id="sign-in-link" href="/login">Sign in</a>
        </p>
        <form id="accept-form" hidden>
            <div id="new-account-fields">
                <label for="name">Name</label>
                <input id="name" name="name" autocomplete="name" maxlength="100">
                <label for="password">Create a password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="12">
                <label for="password-confirmation">Confirm password</label>
                <input id="password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12">
            </div>
            <button type="submit">Accept invitation</button>
        </form>
    </section>
</main>
<script>
(() => {
    const token = window.location.hash.slice(1);
    if (token) history.replaceState(null, '', window.location.pathname + window.location.search);

    const status = document.getElementById('status');
    const invitation = document.getElementById('invitation');
    const acceptForm = document.getElementById('accept-form');
    const newAccountFields = document.getElementById('new-account-fields');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const previewUrl = @json(route('event-manager-invitations.preview', absolute: false));
    const acceptUrl = @json(route('event-manager-invitations.accept', absolute: false));

    const post = async (url, data) => fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        body: JSON.stringify(data),
    });

    (async () => {
        if (token && !/^[a-f0-9]{64}$/.test(token)) {
            status.textContent = 'This invitation is invalid or expired.';
            return;
        }

        try {
            const response = await post(previewUrl, token ? { token } : {});
            const data = await response.json();
            if (!response.ok || !data.invitation) throw new Error();

            document.getElementById('event-title').textContent = data.invitation.event_title;
            document.getElementById('invite-email').textContent = data.invitation.email;
            invitation.hidden = false;
            status.textContent = 'Review your invitation.';

            if (!data.invitation.can_accept) {
                const signIn = document.getElementById('sign-in');
                signIn.hidden = false;
                document.getElementById('sign-in-message').textContent = data.invitation.sign_in_url
                    ? 'Sign in to the invited account to accept. '
                    : 'Sign out, then reopen the invitation email in this browser. ';
                if (data.invitation.sign_in_url) {
                    document.getElementById('sign-in-link').href = data.invitation.sign_in_url;
                } else {
                    document.getElementById('sign-in-link').hidden = true;
                }
            } else if (data.invitation.requires_account_creation) {
                newAccountFields.hidden = false;
                acceptForm.hidden = false;
                document.getElementById('name').required = true;
                document.getElementById('password').required = true;
                document.getElementById('password-confirmation').required = true;
            } else {
                newAccountFields.hidden = true;
                acceptForm.hidden = false;
            }
        } catch {
            status.textContent = 'This invitation is invalid or expired.';
        }
    })();

    acceptForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        status.textContent = 'Accepting invitation…';
        const data = Object.fromEntries(new FormData(acceptForm).entries());
        try {
            const response = await post(acceptUrl, data);
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'This invitation could not be accepted.');
            window.location.assign(result.redirect);
        } catch (error) {
            status.textContent = error.message || 'This invitation could not be accepted.';
        }
    });
})();
</script>
</body>
</html>
