@props(['variant' => 'horizontal', 'alt' => 'Ingress', 'class' => ''])

@php
    $logoAssets = [
        'horizontal' => 'ingress-logo-horizontal.png',
        'horizontal-transparent' => 'ingress-logo-horizontal-transparent.png',
        'symbol' => 'ingress-gateway-symbol.png',
        'symbol-transparent' => 'ingress-gateway-symbol-transparent.png',
        'app-icon' => 'ingress-app-icon.png',
    ];

    $assetName = $logoAssets[$variant] ?? $logoAssets['horizontal'];
@endphp

<img class="app-logo app-logo--{{ $variant }} {{ $class }}" src="{{ asset('images/' . $assetName) }}" alt="{{ $alt }}" {{ $attributes }}>
