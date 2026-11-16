@props(['title', 'subtitle' => null])

<header class="page-header page-header--editorial">
    <x-page-header-watermark />
    <div class="page-header__copy">
        <h1 class="page-header__title">{{ $title }}</h1>
        @if ($subtitle)
            <p class="page-header__subtitle">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="page-header__actions">{{ $actions }}</div>
    @endisset
</header>
