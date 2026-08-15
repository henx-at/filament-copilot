<?php

use EslamRedaDiv\FilamentCopilot\FilamentCopilotPlugin;
use EslamRedaDiv\FilamentCopilot\Pages\CopilotDashboardPage;
use EslamRedaDiv\FilamentCopilot\Resources\CopilotAuditLogs\CopilotAuditLogResource;
use EslamRedaDiv\FilamentCopilot\Resources\CopilotConversations\CopilotConversationResource;
use EslamRedaDiv\FilamentCopilot\Resources\CopilotRateLimits\CopilotRateLimitResource;
use Filament\Panel;

function makePanelForManagementRegistrationTest(string $id): Panel
{
    return Panel::make()->id($id);
}

it('registers management resources and pages when enabled via config only', function () {
    config(['filament-copilot.management.enabled' => true]);

    $panel = makePanelForManagementRegistrationTest('management-config-enabled');
    $plugin = FilamentCopilotPlugin::make();

    $plugin->register($panel);

    expect($panel->getResources())
        ->toContain(CopilotConversationResource::class)
        ->toContain(CopilotAuditLogResource::class)
        ->toContain(CopilotRateLimitResource::class)
        ->and($panel->getPages())->toContain(CopilotDashboardPage::class);
});

it('does not register management resources and pages when disabled', function () {
    config(['filament-copilot.management.enabled' => false]);

    $panel = makePanelForManagementRegistrationTest('management-config-disabled');
    $plugin = FilamentCopilotPlugin::make();

    $plugin->register($panel);

    expect($panel->getResources())->toBe([])
        ->and($panel->getPages())->toBe([]);
});

it('registers management resources and pages via the fluent setter regardless of config', function () {
    config(['filament-copilot.management.enabled' => false]);

    $panel = makePanelForManagementRegistrationTest('management-setter-enabled');
    $plugin = FilamentCopilotPlugin::make()->managementEnabled();

    $plugin->register($panel);

    expect($panel->getResources())
        ->toContain(CopilotConversationResource::class)
        ->toContain(CopilotAuditLogResource::class)
        ->toContain(CopilotRateLimitResource::class)
        ->and($panel->getPages())->toContain(CopilotDashboardPage::class);
});
