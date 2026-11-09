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
            <span>Event handoff, staff pairing, and synchronization actions will be available here after their operator workflows are added.</span>
        </div>
    </section>
@endsection
