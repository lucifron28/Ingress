@extends('layouts.app')

@section('content')
<x-page-header title="Edit Event" subtitle="Update the event schedule, registration, and admission policies">
    <x-slot:actions>
        <a href="{{ route('organizer.events.show', $event) }}" class="btn-secondary">
            <x-icon name="eye" size="17" />
            <span>Back to Overview</span>
        </a>
    </x-slot:actions>
</x-page-header>

<x-event-island-nav :event="$event" active="overview" />

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
    <form method="POST" action="{{ route('organizer.events.update', $event) }}">
        @csrf
        @method('PUT')
        @include('organizer.events.partials.form-fields', [
            'event' => $event,
            'submitLabel' => 'Save Changes',
            'cancelUrl' => route('organizer.events.show', $event),
        ])
    </form>
</div>
@endsection
