@props(['href' => null, 'variant' => 'primary', 'type' => 'button'])

@php
    $variantClass = in_array($variant, ['primary', 'secondary', 'header'], true)
        ? 'button--'.$variant
        : 'button--primary';
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'button '.$variantClass]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => 'button '.$variantClass]) }}>{{ $slot }}</button>
@endif
