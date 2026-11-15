@extends('layouts.node-operator', ['title' => 'Operator sign in'])

@section('content')
    <section class="card">
        <h1>Event Node Operator</h1>
        <p>Sign in with the operator account created on this Event Node.</p>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        @if (config('ingress.lan_demo') === true && ! \App\Models\NodeOperator::query()->exists())
            <p><a href="{{ route('node.operator.setup', absolute: false) }}">Create the first local operator</a></p>
        @endif

        @if ($errors->any())
            <div class="error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="post" action="{{ route('node.operator.login', absolute: false) }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <button type="submit">Sign in</button>
        </form>
    </section>
@endsection
