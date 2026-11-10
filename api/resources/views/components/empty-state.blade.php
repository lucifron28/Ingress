@props(['icon' => 'calendar-days', 'title', 'description'])

<div class="empty-state">
    <span class="empty-state__icon">
        <x-icon :name="$icon" size="24" />
    </span>
    <h3 class="empty-state__title">{{ $title }}</h3>
    <p class="empty-state__description">{{ $description }}</p>
    @isset($action)
        <div class="empty-state__action">{{ $action }}</div>
    @endisset
</div>
