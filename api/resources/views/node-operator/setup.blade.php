@extends('layouts.node-operator', ['title' => 'Local operator setup'])

@section('content')
    <section class="card">
        <h1>Create the local operator</h1>
        <p>Use this account to manage the Event Node on this laptop.</p>
        @if ($errors->any())
            <div class="error" role="alert">{{ $errors->first() }}</div>
        @endif
        <form method="post" action="{{ route('node.operator.setup.store', absolute: false) }}">
            @csrf
            <label for="name">Name</label>
            <input id="name" name="name" value="{{ old('name') }}" maxlength="100" required autofocus>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" required>
            <label for="password">Password (at least 12 characters)</label>
            <input id="password" name="password" type="password" minlength="12" maxlength="72" required>
            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="72" required>
            <button type="submit">Create operator</button>
        </form>
    </section>
@endsection
