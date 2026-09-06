<?php

use EslamRedaDiv\FilamentCopilot\Agent\CopilotAgent;
use EslamRedaDiv\FilamentCopilot\FilamentCopilotPlugin;
use EslamRedaDiv\FilamentCopilot\Http\Controllers\StreamController;
use EslamRedaDiv\FilamentCopilot\Services\ToolRegistry;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;

function swapFilamentForStreamErrorHygieneTest($user, string $panelId = 'admin'): void
{
    $plugin = new FilamentCopilotPlugin;

    $panel = new class($panelId)
    {
        public function __construct(private string $id) {}

        public function getId(): string
        {
            return $this->id;
        }
    };

    $guard = new class($user)
    {
        public function __construct(private $user) {}

        public function user()
        {
            return $this->user;
        }
    };

    $manager = new class($guard, $panel, $plugin)
    {
        public function __construct(private $guard, private $panel, private FilamentCopilotPlugin $plugin) {}

        public function getPlugin(string $pluginId): FilamentCopilotPlugin
        {
            return $this->plugin;
        }

        public function auth()
        {
            return $this->guard;
        }

        public function setCurrentPanel(string $panelId): void {}

        public function getCurrentPanel()
        {
            return $this->panel;
        }

        public function getTenant()
        {
            return null;
        }
    };

    app()->instance('filament', $manager);
    Filament::swap($manager);
}

function fakeThrowingAgentForErrorHygieneTest(Throwable $exception): void
{
    $toolRegistry = Mockery::mock(ToolRegistry::class);
    $toolRegistry->shouldReceive('buildTools')->andReturn([]);
    app()->instance(ToolRegistry::class, $toolRegistry);

    $agent = Mockery::mock(CopilotAgent::class);
    $agent->shouldReceive('forPanel')->andReturnSelf();
    $agent->shouldReceive('forUser')->andReturnSelf();
    $agent->shouldReceive('forTenant')->andReturnSelf();
    $agent->shouldReceive('withTools')->andReturnSelf();
    $agent->shouldReceive('withSystemPrompt')->andReturnSelf();
    $agent->shouldReceive('stream')->andThrow($exception);
    app()->instance(CopilotAgent::class, $agent);
}

/**
 * Run a stream request and return the raw SSE body.
 *
 * Same buffer dance as StreamMessageIdTest: the controller discards the
 * innermost buffer before writing and flushes after every event, so the
 * events collect one level up and are drained into the outermost buffer.
 */
function captureStreamOutputForErrorHygieneTest(): string
{
    $response = app(StreamController::class)->stream(Request::create('/copilot/stream', 'POST', [
        'message' => 'What is the total revenue?',
        'panel_id' => 'admin',
    ]));

    $initialBufferLevel = ob_get_level();

    ob_start();
    ob_start();
    ob_start();

    $response->sendContent();

    while (ob_get_level() > $initialBufferLevel + 1) {
        ob_end_flush();
    }

    return (string) ob_get_clean();
}

const ERROR_HYGIENE_RAW_MESSAGE = "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'secret_column' (Connection: mysql, SQL: select `secret_column` from `internal_table`)";

it('replaces the raw exception message with a correlation id when debug is off', function () {
    config(['app.debug' => false]);
    Exceptions::fake();

    $user = createTestUser();
    swapFilamentForStreamErrorHygieneTest($user);
    fakeThrowingAgentForErrorHygieneTest(new RuntimeException(ERROR_HYGIENE_RAW_MESSAGE));

    $output = captureStreamOutputForErrorHygieneTest();

    expect($output)->toContain('event: error')
        ->not->toContain('SQLSTATE')
        ->not->toContain('secret_column')
        // The stream still terminates cleanly for the client.
        ->toContain('event: done');

    // The generic line carries the correlation id (ULID alphabet, 6 chars).
    expect($output)->toMatch('/\(ref: [0-9A-Z]{6}\)/');
    preg_match('/\(ref: ([0-9A-Z]{6})\)/', $output, $matches);
    $ref = $matches[1];

    // The full exception is reported server-side under the same id, with the
    // original exception preserved as the previous throwable.
    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), "[copilot:{$ref}]")
        && $e->getPrevious()?->getMessage() === ERROR_HYGIENE_RAW_MESSAGE);
});

it('keeps showing the raw exception message when debug is on', function () {
    config(['app.debug' => true]);
    Exceptions::fake();

    $user = createTestUser();
    swapFilamentForStreamErrorHygieneTest($user);
    fakeThrowingAgentForErrorHygieneTest(new RuntimeException(ERROR_HYGIENE_RAW_MESSAGE));

    $output = captureStreamOutputForErrorHygieneTest();

    expect($output)->toContain('event: error')
        ->toContain('secret_column')
        ->toContain('event: done');

    // Reported server-side either way — debug mode only changes what the
    // chat UI shows, not what lands in the log.
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getPrevious()?->getMessage() === ERROR_HYGIENE_RAW_MESSAGE);
});
