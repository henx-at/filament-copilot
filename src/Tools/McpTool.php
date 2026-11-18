<?php

declare(strict_types=1);

namespace EslamRedaDiv\FilamentCopilot\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool as McpServerTool;
use ReflectionClass;
use Stringable;

/**
 * Bridges one laravel/mcp tool into the chat (FilamentCopilotPlugin::mcpTools()).
 *
 * Write tools (no #[IsReadOnly]) implement Approvable: they only run after the
 * user approved them in the chat. Set $tenantArgument in a subclass when the
 * MCP tools take the tenant id as an argument: it is removed from the schema
 * the model sees and always forced to the current Filament tenant, so a
 * prompt-injected model cannot reach another tenant.
 */
class McpTool extends BaseTool implements Approvable
{
    use InteractsWithApprovals;

    protected static ?string $tenantArgument = null;

    public function __construct(protected McpServerTool $mcpTool) {}

    /**
     * @param  class-string|list<class-string>  $tools  MCP tool classes, or a Laravel\Mcp\Server class
     * @param  list<class-string>  $except
     * @return list<static>
     */
    public static function make(string | array $tools, array $except = []): array
    {
        if (is_string($tools) && is_subclass_of($tools, Server::class)) {
            $tools = (new ReflectionClass($tools))->getDefaultProperties()['tools'] ?? [];
        }

        $result = [];

        foreach ((array) $tools as $class) {
            if (in_array($class, $except, true)) {
                continue;
            }

            $mcpTool = is_string($class) ? app($class) : $class;

            if ($mcpTool->eligibleForRegistration()) {
                $result[] = new static($mcpTool);
            }
        }

        return $result;
    }

    public function mcpTool(): McpServerTool
    {
        return $this->mcpTool;
    }

    public function name(): string
    {
        return str_replace('-', '_', $this->mcpTool->name());
    }

    public function description(): Stringable | string
    {
        $description = $this->mcpTool->description();

        return $this->isWriteTool()
            ? $description.' Write action: runs only after the user approved it.'
            : $description;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $properties = $this->mcpTool->schema($schema);

        if (static::$tenantArgument !== null) {
            unset($properties[static::$tenantArgument]);
        }

        return $properties;
    }

    public function handle(Request $request): Stringable | string
    {
        $arguments = $request->all();

        if ($this->takesTenantArgument()) {
            if ($this->tenant === null) {
                return 'Error: no tenant selected.';
            }

            $arguments[static::$tenantArgument] = (string) $this->tenant->getKey();
        }

        try {
            $response = app()->call([$this->mcpTool, 'handle'], ['request' => new McpRequest($arguments)]);
        } catch (ValidationException $exception) {
            return 'Error: '.implode(' ', $exception->validator->errors()->all());
        }

        $text = (string) $response->content();

        // No dispatchToolExecuted(): StreamController records every tool call from the stream events.
        return $response->isError() ? "Error: {$text}" : $text;
    }

    public function isWriteTool(): bool
    {
        return ($this->mcpTool->annotations()['readOnlyHint'] ?? false) !== true;
    }

    /**
     * Text of the approval card. Override for a readable summary instead of raw arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function approvalReason(array $arguments): string
    {
        return $this->name().': '.json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function needsApproval(Request $request): Approval | bool
    {
        if (! $this->isWriteTool()) {
            return false;
        }

        // Empty arguments (e.g. a truncated tool call) are not worth an approval
        // card: run the tool so its validation error goes back to the model.
        if (array_key_exists('action', $this->mcpTool->schema(new JsonSchemaTypeFactory)) && blank($request['action'] ?? null)) {
            return false;
        }

        return Approval::required($this->approvalReason($request->all()));
    }

    protected function takesTenantArgument(): bool
    {
        return static::$tenantArgument !== null
            && array_key_exists(static::$tenantArgument, $this->mcpTool->schema(new JsonSchemaTypeFactory));
    }
}
