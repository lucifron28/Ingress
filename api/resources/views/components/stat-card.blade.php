@props(['icon', 'label', 'value'])

<div class="stat-card">
    <span class="stat-card__icon">
        <x-icon :name="$icon" size="20" />
    </span>
    <dt class="stat-card__label">{{ $label }}</dt>
    <dd class="stat-card__value">{{ $value }}</dd>
</div>
