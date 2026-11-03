<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Complete your student account</title>
    <style>body{font:16px system-ui;max-width:32rem;margin:2rem auto;padding:0 1rem;color:#1e293b}label{display:block;margin-top:1rem}input{box-sizing:border-box;width:100%;padding:.7rem}button{margin-top:1.5rem;padding:.8rem 1.2rem;background:#800000;color:white;border:0}.error{color:#991b1b}</style>
</head>
<body>
    <h1>Complete your student account</h1>
    <p>Enter your details to finish creating your Ingress account. Your student number is self-reported.</p>
    @if (!empty($error)) <p class="error">{{ $error }}</p> @endif
    @if (isset($errors) && $errors->any())
        <ul class="error">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    @endif
    <noscript>JavaScript is required to read the verification link.</noscript>
    <form method="post" action="complete">
        @csrf
        <input type="hidden" name="token" id="token" value="{{ $token ?? '' }}">
        <label for="name">Full name</label><input id="name" name="name" autocomplete="name" maxlength="100" required>
        <label for="student_id">Student number</label><input id="student_id" name="student_id" maxlength="64" autocomplete="off" required>
        <label for="password">Password, at least 12 characters</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
        <label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
        <button type="submit">Create account</button>
    </form>
    <script>
        const token = location.hash.slice(1);
        if (/^[a-f0-9]{64}$/.test(token)) {
            document.getElementById('token').value = token;
            history.replaceState(null, '', location.pathname);
        }
    </script>
</body>
</html>
