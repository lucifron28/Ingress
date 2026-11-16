@props(['href', 'icon', 'label', 'active' => false])

<a
    class="app-sidebar__link {{ $active ? 'is-active' : '' }}"
    href="{{ $href }}"
    aria-label="{{ $label }}"
    title="{{ $label }}"
    @if ($active) aria-current="page" @endif
>
    <x-icon :name="$icon" size="20" />
    <span class="app-sidebar__link-label">{{ $label }}</span>
</a>
