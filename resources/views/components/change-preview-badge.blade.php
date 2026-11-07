@php
    $href = $record['url'] ?? null;
    // Only same-site links: the URL is built by the host app.
    $href = is_string($href) && (str_starts_with($href, url('/')) || (str_starts_with($href, '/') && ! str_starts_with($href, '//'))) ? $href : null;
@endphp
<x-filament::badge
    :tag="$href ? 'a' : 'span'"
    :href="$href"
    :color="$record['color'] ?? 'gray'"
    :icon="$record['icon'] ?? null"
    size="sm"
>{{ $record['label'] }}</x-filament::badge>
