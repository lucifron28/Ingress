@extends('layouts.app')

@section('content')
<x-page-header title="Create Campus Event" subtitle="Set up event information, schedule, registration, and admission policies">
    <x-slot:actions>
        <a href="{{ route('organizer.events.index') }}" class="btn-secondary">Cancel</a>
    </x-slot:actions>
</x-page-header>

@if ($errors->any())
    <div class="form-error-summary" role="alert" aria-labelledby="event-errors-title">
        <h2 id="event-errors-title">Please review the following fields</h2>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card event-form-card">
    <form method="POST" action="{{ route('organizer.events.store') }}">
        @csrf
        @include('organizer.events.partials.form-fields', [
            'event' => null,
            'submitLabel' => 'Create Event (Draft)',
            'cancelUrl' => route('organizer.events.index'),
        ])
    </form>
</div>
@endsection
