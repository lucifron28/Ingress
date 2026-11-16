@props(['event', 'mobile' => false])

<div @class(['dashboard-event-actions', 'event-card__actions' => $mobile])>
    <x-ui.button
        :href="route('organizer.events.show', $event)"
        variant="secondary"
        class="dashboard-event-action"
    >
        <x-icon name="settings-2" size="16" />
        <span>Manage</span>
    </x-ui.button>
    <x-ui.button
        :href="route('organizer.attendance', $event)"
        variant="secondary"
        class="dashboard-event-action"
    >
        <x-icon name="chart-no-axes-column" size="16" />
        <span>Attendance</span>
    </x-ui.button>
</div>
