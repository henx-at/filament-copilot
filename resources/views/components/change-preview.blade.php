{{-- Structured preview of a write tool call (approval card) or of its result
     (executed tool call). Shape: see FilamentCopilotPlugin::getApprovalPreview().
     Every value is escaped; only server-built previews reach this component. --}}
@php
    $tone = $preview['tone'] ?? null;
    $records = $preview['records'] ?? [];
    $fields = $preview['fields'] ?? [];
    $notes = $preview['notes'] ?? [];
@endphp

<div class="copilot-change-preview copilot-change-preview--{{ $tone ?? 'neutral' }}">
    @if (filled($preview['title'] ?? null))
        <div class="copilot-change-preview-title">{{ $preview['title'] }}</div>
    @endif

    @if ($records !== [])
        <div class="copilot-change-preview-records">
            @foreach ($records as $record)
                @include('filament-copilot::components.change-preview-badge', ['record' => $record])
            @endforeach
        </div>
    @endif

    @if ($fields !== [])
        <dl class="copilot-change-preview-fields">
            @foreach ($fields as $field)
                <dt>{{ $field['label'] }}</dt>
                <dd>
                    @if (array_key_exists('old', $field))
                        <span class="copilot-change-preview-old">@include('filament-copilot::components.change-preview-value', ['value' => $field['old']])</span>
                        <span aria-hidden="true">→</span>
                        <span class="sr-only">{{ __('filament-copilot::filament-copilot.changes_to') }}</span>
                    @endif
                    <span class="copilot-change-preview-new">@include('filament-copilot::components.change-preview-value', ['value' => $field['new'] ?? null])</span>
                </dd>
            @endforeach
        </dl>
    @endif

    @foreach ($notes as $note)
        <p class="copilot-change-preview-note">{{ $note }}</p>
    @endforeach
</div>
