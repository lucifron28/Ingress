@props(['variant' => 'horizontal', 'alt' => 'Ingress', 'class' => ''])

@php
    $logoAssets = [
        'horizontal' => 'ingress-logo-horizontal.png',
        'horizontal-light' => 'ingress-logo-horizontal-light.svg',
        'symbol' => 'ingress-gateway-symbol.png',
        'app-icon' => 'ingress-app-icon.png',
    ];

    $assetName = $logoAssets[$variant] ?? $logoAssets['horizontal'];
@endphp

<img class="app-logo app-logo--{{ $variant }} {{ $class }}" src="{{ asset('images/' . $assetName) }}" alt="{{ $alt }}">
