<?php

declare(strict_types=1);

namespace EslamRedaDiv\FilamentCopilot\Pages;

use EslamRedaDiv\FilamentCopilot\FilamentCopilotPlugin;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;

/**
 * Full-page chat: the same chat component as the drawer, embedded in the
 * page flow with the conversation history as a left column. Enabled via
 * FilamentCopilotPlugin::fullPage(); the drawer links here with
 * ?conversation=<id> to continue the open conversation.
 */
class CopilotChatPage extends Page
{
    protected static ?string $slug = 'copilot';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament-copilot::pages.copilot-chat-page';

    protected Width | string | null $maxContentWidth = Width::Full;

    public static function canAccess(): bool
    {
        try {
            return FilamentCopilotPlugin::get()->isAuthorized();
        } catch (\Throwable) {
            return false;
        }
    }

    public function getTitle(): string
    {
        return __('filament-copilot::filament-copilot.title');
    }

    public function getConversationId(): ?string
    {
        $conversation = request()->query('conversation');

        return is_string($conversation) && $conversation !== '' ? $conversation : null;
    }
}
