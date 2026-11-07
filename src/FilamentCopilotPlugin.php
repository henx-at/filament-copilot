<?php

declare(strict_types=1);

namespace EslamRedaDiv\FilamentCopilot;

use Closure;
use EslamRedaDiv\FilamentCopilot\Pages\CopilotChatPage;
use EslamRedaDiv\FilamentCopilot\Pages\CopilotDashboardPage;
use EslamRedaDiv\FilamentCopilot\Resources\CopilotAuditLogs\CopilotAuditLogResource;
use EslamRedaDiv\FilamentCopilot\Resources\CopilotConversations\CopilotConversationResource;
use EslamRedaDiv\FilamentCopilot\Resources\CopilotRateLimits\CopilotRateLimitResource;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Blade;

class FilamentCopilotPlugin implements Plugin
{
    protected bool $managementEnabled = false;

    protected ?string $managementGuard = null;

    protected ?string $provider = null;

    protected ?string $model = null;

    protected string | Closure | null $systemPrompt = null;

    /** @var array<\Laravel\Ai\Contracts\Tool> */
    protected array $globalTools = [];

    protected array $quickActions = [];

    protected ?Closure $authorizeUsing = null;

    protected ?bool $tokenBudgetEnabled = null;

    protected ?int $dailyTokenBudget = null;

    protected ?int $monthlyTokenBudget = null;

    protected ?bool $rateLimitEnabled = null;

    protected ?bool $memoryEnabled = null;

    protected ?int $maxMemoriesPerUser = null;

    protected ?bool $respectAuthorization = null;

    protected string | Closure $sidebarWidth = '20rem';

    protected ?Closure $approvalPreviewUsing = null;

    protected bool $fullPage = false;

    /** @var class-string<CopilotChatPage> */
    protected string $fullPageClass = CopilotChatPage::class;

    protected ?Closure $messageBlockUsing = null;

    protected ?Closure $chatNoticeUsing = null;

    protected ?Closure $toolResultPreviewUsing = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public function sidebarWidth(string | Closure $width): static
    {
        $this->sidebarWidth = $width;

        return $this;
    }

    public function getSidebarWidth(): ?string
    {
        return $this->sidebarWidth ?? '20rem';
    }

    /**
     * Host-defined gate for new messages (e.g. a spending budget). The
     * closure receives (Authenticatable $user, ?Model $tenant) and returns
     * the message to show instead of answering, or null to proceed.
     * Approval decisions of a running turn are never blocked.
     */
    public function messageBlockUsing(?Closure $callback): static
    {
        $this->messageBlockUsing = $callback;

        return $this;
    }

    public function getMessageBlock(Authenticatable $user, ?\Illuminate\Database\Eloquent\Model $tenant): ?string
    {
        return $this->messageBlockUsing ? ($this->messageBlockUsing)($user, $tenant) : null;
    }

    /**
     * Host-defined notice shown above the chat input (e.g. "80 % of the
     * budget used"). Re-evaluated after every answer; null hides it.
     */
    public function chatNoticeUsing(?Closure $callback): static
    {
        $this->chatNoticeUsing = $callback;

        return $this;
    }

    public function getChatNotice(): ?string
    {
        return $this->chatNoticeUsing ? ($this->chatNoticeUsing)() : null;
    }

    /**
     * Registers the full-page chat (CopilotChatPage) and an "open full page"
     * button in the drawer header. The drawer is hidden on that page.
     */
    /**
     * @param  class-string<CopilotChatPage>|null  $page  a subclass, e.g. to add app interfaces
     */
    public function fullPage(bool $enabled = true, ?string $page = null): static
    {
        $this->fullPage = $enabled;
        $this->fullPageClass = $page ?? CopilotChatPage::class;

        return $this;
    }

    public function isFullPageEnabled(): bool
    {
        return $this->fullPage;
    }

    public function getFullPageUrl(): ?string
    {
        if (! $this->fullPage || ! $this->fullPageClass::canAccess()) {
            return null;
        }

        try {
            return $this->fullPageClass::getUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function isOnFullPage(): bool
    {
        return $this->fullPage && request()->routeIs($this->fullPageClass::getRouteName());
    }

    /**
     * Structured preview for the approval card of a write tool, rendered by
     * the `change-preview` component instead of the plain approval reason.
     *
     * The closure receives (string $tool, array $arguments) and returns the
     * preview array (see getApprovalPreview()) or null for the plain card.
     */
    public function approvalPreviewUsing(?Closure $callback): static
    {
        $this->approvalPreviewUsing = $callback;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{title?: string, tone?: string, records?: list<array{label: string, url?: ?string, icon?: mixed, color?: mixed}>, fields?: list<array{label: string, old?: ?string, new?: ?string}>, notes?: list<string>}|null
     */
    public function getApprovalPreview(string $tool, array $arguments): ?array
    {
        return $this->approvalPreviewUsing ? ($this->approvalPreviewUsing)($tool, $arguments) : null;
    }

    /**
     * Preview shown under an executed tool call (e.g. a link to the record
     * it created). The closure receives (string $tool, ?array $arguments,
     * ?string $result) and returns the same shape as getApprovalPreview().
     * It is re-evaluated on the server, so it must only resolve data the
     * current user may see.
     */
    public function toolResultPreviewUsing(?Closure $callback): static
    {
        $this->toolResultPreviewUsing = $callback;

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $arguments
     * @return array<string, mixed>|null
     */
    public function getToolResultPreview(string $tool, ?array $arguments, ?string $result): ?array
    {
        return $this->toolResultPreviewUsing ? ($this->toolResultPreviewUsing)($tool, $arguments, $result) : null;
    }

    public static function get(): static
    {
        return filament(app(static::class)->getId());
    }

    public function getId(): string
    {
        return 'filament-copilot';
    }

    public function managementEnabled(bool $enabled = true): static
    {
        $this->managementEnabled = $enabled;

        return $this;
    }

    public function isManagementEnabled(): bool
    {
        return $this->managementEnabled || config('filament-copilot.management.enabled', false);
    }

    public function managementGuard(?string $guard): static
    {
        $this->managementGuard = $guard;

        return $this;
    }

    public function getManagementGuard(): ?string
    {
        return $this->managementGuard ?? config('filament-copilot.management.guard');
    }

    public function provider(string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider ?? config('filament-copilot.provider', 'openai');
    }

    public function model(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model ?? config('filament-copilot.model');
    }

    public function systemPrompt(string | Closure | null $prompt): static
    {
        $this->systemPrompt = $prompt;

        return $this;
    }

    public function getSystemPrompt(): ?string
    {
        $prompt = $this->systemPrompt ?? config('filament-copilot.system_prompt');

        if ($prompt instanceof Closure) {
            return (string) app()->call($prompt, [
                'plugin' => $this,
                'request' => request(),
            ]);
        }

        return $prompt;
    }

    public function globalTools(array $tools): static
    {
        $this->globalTools = $tools;

        return $this;
    }

    public function getGlobalTools(): array
    {
        return ! empty($this->globalTools) ? $this->globalTools : config('filament-copilot.global_tools', []);
    }

    public function quickActions(array $actions): static
    {
        $this->quickActions = $actions;

        return $this;
    }

    public function getQuickActions(): array
    {
        return ! empty($this->quickActions) ? $this->quickActions : config('filament-copilot.quick_actions', []);
    }

    public function authorizeUsing(?Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    public function getAuthorizeUsing(): ?Closure
    {
        return $this->authorizeUsing;
    }

    public function isAuthorized(?Authenticatable $user = null): bool
    {
        $user ??= Filament::auth()->user();

        if (! $user) {
            return false;
        }

        $callback = $this->getAuthorizeUsing();

        if (! $callback) {
            return true;
        }

        try {
            return (bool) $callback($user);
        } catch (\Throwable) {
            return false;
        }
    }

    public function tokenBudgetEnabled(bool $enabled = true): static
    {
        $this->tokenBudgetEnabled = $enabled;

        return $this;
    }

    public function isTokenBudgetEnabled(): bool
    {
        return $this->tokenBudgetEnabled ?? config('filament-copilot.token_budget.enabled', false);
    }

    public function dailyTokenBudget(?int $budget): static
    {
        $this->dailyTokenBudget = $budget;

        return $this;
    }

    public function getDailyTokenBudget(): ?int
    {
        return $this->dailyTokenBudget ?? config('filament-copilot.token_budget.daily_budget');
    }

    public function monthlyTokenBudget(?int $budget): static
    {
        $this->monthlyTokenBudget = $budget;

        return $this;
    }

    public function getMonthlyTokenBudget(): ?int
    {
        return $this->monthlyTokenBudget ?? config('filament-copilot.token_budget.monthly_budget');
    }

    public function rateLimitEnabled(bool $enabled = true): static
    {
        $this->rateLimitEnabled = $enabled;

        return $this;
    }

    public function isRateLimitEnabled(): bool
    {
        return $this->rateLimitEnabled ?? config('filament-copilot.rate_limits.enabled', false);
    }

    public function memoryEnabled(bool $enabled = true): static
    {
        $this->memoryEnabled = $enabled;

        return $this;
    }

    public function isMemoryEnabled(): bool
    {
        return $this->memoryEnabled ?? config('filament-copilot.memory.enabled', true);
    }

    public function maxMemoriesPerUser(int $max): static
    {
        $this->maxMemoriesPerUser = $max;

        return $this;
    }

    public function getMaxMemoriesPerUser(): int
    {
        return $this->maxMemoriesPerUser ?? config('filament-copilot.memory.max_memories_per_user', 100);
    }

    public function respectAuthorization(bool $respect = true): static
    {
        $this->respectAuthorization = $respect;

        return $this;
    }

    public function shouldRespectAuthorization(): bool
    {
        return $this->respectAuthorization ?? config('filament-copilot.respect_authorization', true);
    }

    public function register(Panel $panel): void
    {
        if ($this->fullPage) {
            $panel->pages([$this->fullPageClass]);
        }

        if ($this->isManagementEnabled()) {
            $panel->resources([
                CopilotConversationResource::class,
                CopilotAuditLogResource::class,
                CopilotRateLimitResource::class,
            ]);

            $panel->pages([
                CopilotDashboardPage::class,
            ]);
        }
    }

    public function boot(Panel $panel): void
    {
        // Place the trigger button in the top bar next to global search
        FilamentView::registerRenderHook(
            PanelsRenderHook::GLOBAL_SEARCH_AFTER,
            fn (): string => $this->isAuthorized() && ! $this->isOnFullPage()
                ? Blade::render('@livewire(\'filament-copilot-button\')')
                : '',
        );

        // Place the chat modal at the end of the body
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): string => $this->isAuthorized() && ! $this->isOnFullPage()
                ? Blade::render('@livewire(\'filament-copilot-chat\')')
                : '',
        );
    }
}
