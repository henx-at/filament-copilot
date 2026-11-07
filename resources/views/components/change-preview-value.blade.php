@if (is_array($value) && isset($value['records']))
    @foreach ($value['records'] as $record)
        @include('filament-copilot::components.change-preview-badge', ['record' => $record])
    @endforeach
@elseif (filled($value))
    {{ $value }}
@else
    –
@endif
