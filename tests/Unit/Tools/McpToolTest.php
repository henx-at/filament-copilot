<?php

use EslamRedaDiv\FilamentCopilot\FilamentCopilotPlugin;
use EslamRedaDiv\FilamentCopilot\Tools\McpTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class McpToolTestRead extends Tool
{
    protected string $name = 'list-things';

    protected string $description = 'Lists things.';

    public function handle(McpRequest $request): Response
    {
        return Response::text('things of '.$request->get('tenant_id'));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['tenant_id' => $schema->string()->required()];
    }
}

class McpToolTestWrite extends Tool
{
    protected string $name = 'manage-thing';

    protected string $description = 'Changes a thing.';

    public function handle(McpRequest $request): Response
    {
        return Response::error('nope');
    }

    public function schema(JsonSchema $schema): array
    {
        return ['action' => $schema->string()->required()];
    }
}

class McpToolTestServer extends Server
{
    protected array $tools = [McpToolTestRead::class, McpToolTestWrite::class];
}

class McpToolTestTenantTool extends McpTool
{
    protected static ?string $tenantArgument = 'tenant_id';

    public function approvalFor(array $arguments): Approval|bool
    {
        return $this->needsApproval(new Request($arguments));
    }
}

it('bridges the tools of an MCP server', function () {
    $tools = McpTool::make(McpToolTestServer::class, except: [McpToolTestWrite::class]);

    expect($tools)->toHaveCount(1)
        ->and($tools[0]->name())->toBe('list_things')
        ->and((string) $tools[0]->description())->toBe('Lists things.')
        ->and($tools[0]->isWriteTool())->toBeFalse();
});

it('marks write tools and returns MCP errors as text', function () {
    [$tool] = McpTool::make([McpToolTestWrite::class]);

    expect($tool->isWriteTool())->toBeTrue()
        ->and((string) $tool->description())->toContain('Write action')
        ->and((string) $tool->handle(new Request(['action' => 'x'])))->toBe('Error: nope');
});

it('hides the tenant argument and forces the current tenant', function () {
    [$tool] = McpToolTestTenantTool::make([McpToolTestRead::class]);

    expect($tool->schema(new JsonSchemaTypeFactory))->not->toHaveKey('tenant_id')
        ->and((string) $tool->handle(new Request([])))->toBe('Error: no tenant selected.');

    $tenant = new class extends Model
    {
        protected $attributes = ['id' => 7];
    };

    $tool->forTenant($tenant);

    expect((string) $tool->handle(new Request(['tenant_id' => '999'])))->toBe('things of 7');
});

it('asks for approval only for write tools with arguments', function () {
    [$write] = McpToolTestTenantTool::make([McpToolTestWrite::class]);
    [$read] = McpToolTestTenantTool::make([McpToolTestRead::class]);

    expect($write->approvalFor(['action' => 'delete']))->toBeInstanceOf(Approval::class)
        ->and($write->approvalFor([]))->toBeFalse()
        ->and($read->approvalFor([]))->toBeFalse();
});

it('adds MCP tools to the global tools of the plugin', function () {
    $plugin = FilamentCopilotPlugin::make()->mcpTools(McpToolTestServer::class);

    expect($plugin->getGlobalTools())->toHaveCount(2)
        ->and($plugin->findMcpTool('manage_thing'))->toBeInstanceOf(McpTool::class)
        ->and($plugin->findMcpTool('unknown'))->toBeNull();
});

it('evaluates quick actions given as a closure', function () {
    $plugin = FilamentCopilotPlugin::make()->quickActions(fn (): array => ['Hilfe' => 'Hilf mir']);

    expect($plugin->getQuickActions())->toBe(['Hilfe' => 'Hilf mir']);
});

it('leaves the system prompt alone without page context', function () {
    $plugin = FilamentCopilotPlugin::make()->systemPrompt('Base');

    expect($plugin->getSystemPrompt())->toBe('Base');
});
