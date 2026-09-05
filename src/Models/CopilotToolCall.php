<?php

declare(strict_types=1);

namespace EslamRedaDiv\FilamentCopilot\Models;

use EslamRedaDiv\FilamentCopilot\Enums\ToolCallStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CopilotToolCall extends Model
{
    use HasUlids;

    protected $fillable = [
        'message_id',
        'provider_id',
        'tool_name',
        'tool_input',
        'tool_output',
        'status',
        'requires_approval',
    ];

    protected function casts(): array
    {
        return [
            'tool_input' => 'array',
            'status' => ToolCallStatus::class,
            'requires_approval' => 'boolean',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(CopilotMessage::class, 'message_id');
    }

    public function isPending(): bool
    {
        return $this->status === ToolCallStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === ToolCallStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->status === ToolCallStatus::Rejected;
    }

    public function isExecuted(): bool
    {
        return $this->status === ToolCallStatus::Executed;
    }

    public function approve(): void
    {
        $this->update(['status' => ToolCallStatus::Approved]);
    }

    public function reject(): void
    {
        $this->update(['status' => ToolCallStatus::Rejected]);
    }

    public function markExecuted(string $output): void
    {
        $this->update([
            'status' => ToolCallStatus::Executed,
            'tool_output' => $output,
        ]);
    }

    public function markFailed(string $output): void
    {
        $this->update([
            'status' => ToolCallStatus::Failed,
            'tool_output' => $output,
        ]);
    }
}
