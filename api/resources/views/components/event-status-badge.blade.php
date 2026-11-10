@props(['event'])

@php
    $statusView = match ($event->status) {
        \App\Models\Event::STATUS_PUBLISHED => ['label' => 'Published', 'tone' => 'success', 'icon' => 'check-circle-2'],
        \App\Models\Event::STATUS_DRAFT => ['label' => 'Draft', 'tone' => 'warning', 'icon' => 'file-pen-line'],
        \App\Models\Event::STATUS_CANCELLED => ['label' => 'Cancelled', 'tone' => 'error', 'icon' => 'x-circle'],
        default => ['label' => ucfirst(str_replace('_', ' ', (string) $event->status)), 'tone' => 'neutral', 'icon' => null],
    };
@endphp

<span class="status-badge status-badge--{{ $statusView['tone'] }}">
    @if ($statusView['icon'])
        <x-icon :name="$statusView['icon']" size="15" />
    @endif
    <span>{{ $statusView['label'] }}</span>
</span>
