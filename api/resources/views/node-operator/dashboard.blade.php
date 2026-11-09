@extends('layouts.node-operator', ['title' => 'Operator console'])

@section('content')
    <section class="card">
        <div class="topline">
            <div>
                <h1>Operator console</h1>
                <p>Signed in as {{ $operator->name }}.</p>
            </div>
            <form method="post" action="{{ route('node.operator.logout', absolute: false) }}">
                @csrf
                <button type="submit">Sign out</button>
            </form>
        </div>

        <div class="status" role="status">
            <strong>Console access is active</strong>
            <span>Manage scanner pairing and staff credentials, then follow the staged event handoff.</span>
        </div>

        <p><a href="{{ route('node.operator.staff.index', absolute: false) }}">Open staff and scanner management</a></p>
        <p><a href="{{ route('node.operator.handoff.index', absolute: false) }}">Open event handoff</a></p>
    </section>
@endsection
