@props(['icon', 'label', 'value', 'tone' => 'slate'])

<div class="stat-card stat-card--{{ $tone }}">
    <dt class="stat-card__label">{{ $label }}</dt>
    <span class="stat-card__icon" aria-hidden="true">
        <x-icon :name="$icon" size="34" />
    </span>
    <dd class="stat-card__value">{{ $value }}</dd>
</div>
