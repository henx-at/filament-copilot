<?php

declare(strict_types=1);

namespace EslamRedaDiv\FilamentCopilot\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CopilotConversation extends Model
{
    use HasUlids;

    protected $fillable = [
        'participant_type',
        'participant_id',
        'panel_id',
        'tenant_type',
        'tenant_id',
        'title',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function participant(): MorphTo
    {
        return $this->morphTo();
    }

    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CopilotMessage::class, 'conversation_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(CopilotAuditLog::class, 'conversation_id');
    }

    public function tokenUsages(): HasMany
    {
        return $this->hasMany(CopilotTokenUsage::class, 'conversation_id');
    }

    public function latestMessage(): HasMany
    {
        return $this->messages()->latest()->limit(1);
    }

    public function scopeForPanel($query, string $panelId)
    {
        return $query->where('panel_id', $panelId);
    }

    public function scopeForParticipant($query, Model $participant)
    {
        return $query
            ->where('participant_type', $participant->getMorphClass())
            ->where('participant_id', $participant->getKey());
    }

    public function scopeForTenant($query, ?Model $tenant)
    {
        if ($tenant === null) {
            return $query->whereNull('tenant_type')->whereNull('tenant_id');
        }

        return $query
            ->where('tenant_type', $tenant->getMorphClass())
            ->where('tenant_id', $tenant->getKey());
    }

    public function getTotalTokensAttribute(): int
    {
        return $this->messages()->sum('input_tokens') + $this->messages()->sum('output_tokens');
    }

    /**
     * The participant agreed to let reviewers (e.g. an admin) read this
     * conversation, typically after rating a reply as not helpful.
     */
    public function isSharedForReview(): bool
    {
        return filled($this->metadata['shared_for_review_at'] ?? null);
    }

    public function shareForReview(): void
    {
        $this->update(['metadata' => array_merge($this->metadata ?? [], ['shared_for_review_at' => now()->toIso8601String()])]);
    }

    public function unshareForReview(): void
    {
        $metadata = $this->metadata ?? [];
        unset($metadata['shared_for_review_at']);

        $this->update(['metadata' => $metadata]);
    }

    public function scopeSharedForReview(Builder $query): Builder
    {
        return $query->whereNotNull('metadata->shared_for_review_at');
    }
}
