<?php

namespace EslamRedaDiv\FilamentCopilot\Listeners;

use EslamRedaDiv\FilamentCopilot\Agent\CopilotAgent;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\ToolCall;

/**
 * Writes one line per Copilot model step and tool call to the log channel in
 * filament-copilot.log_channel (duration, finish reason, tokens, sizes), so slow or cut-off replies
 * can be traced afterwards. Arguments and results are logged by size only;
 * their content stays in copilot_tool_calls.
 */
class LogCopilotSteps
{
    public function stepCompleted(StepCompleted $event): void
    {
        if (! $event->agent instanceof CopilotAgent) {
            return;
        }

        $context = [
            'invocation' => $event->invocationId,
            'user' => auth()->id(),
            'step' => $event->stepNumber,
            'model' => $event->model,
            'ms' => (int) round($event->time),
            'finish' => $event->response->finishReason->value,
            'tokens_in' => $event->response->usage->promptTokens,
            'tokens_out' => $event->response->usage->completionTokens,
            'tool_calls' => array_map(
                fn (ToolCall $call): string => $call->name.'('.strlen((string) json_encode($call->arguments)).'b)',
                $event->response->toolCalls,
            ),
        ];

        // Length = reply cut off at MaxTokens; a cut tool call arrives with empty arguments.
        $event->response->finishReason === FinishReason::Length
            ? Log::channel(config('filament-copilot.log_channel'))->warning('Copilot step cut off (max tokens)', $context)
            : Log::channel(config('filament-copilot.log_channel'))->info('Copilot step', $context);
    }

    public function stepFailed(StepFailed $event): void
    {
        if (! $event->agent instanceof CopilotAgent) {
            return;
        }

        Log::channel(config('filament-copilot.log_channel'))->error('Copilot step failed', [
            'invocation' => $event->invocationId,
            'user' => auth()->id(),
            'step' => $event->stepNumber,
            'model' => $event->model,
            'ms' => (int) round($event->time),
            'error' => $event->exception::class.': '.$event->exception->getMessage(),
        ]);
    }

    public function toolInvoked(ToolInvoked $event): void
    {
        if (! $event->agent instanceof CopilotAgent) {
            return;
        }

        Log::channel(config('filament-copilot.log_channel'))->info('Copilot tool', [
            'invocation' => $event->invocationId,
            'tool' => $this->toolName($event->tool),
            'ms' => (int) round($event->time),
            'args_bytes' => strlen((string) json_encode($event->arguments)),
            'result_bytes' => strlen(is_string($event->result) ? $event->result : (string) json_encode($event->result)),
        ]);
    }

    public function toolFailed(ToolFailed $event): void
    {
        if (! $event->agent instanceof CopilotAgent) {
            return;
        }

        Log::channel(config('filament-copilot.log_channel'))->error('Copilot tool failed', [
            'invocation' => $event->invocationId,
            'tool' => $this->toolName($event->tool),
            'ms' => (int) round($event->time),
            'error' => $event->exception::class.': '.$event->exception->getMessage(),
        ]);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            StepCompleted::class => 'stepCompleted',
            StepFailed::class => 'stepFailed',
            ToolInvoked::class => 'toolInvoked',
            ToolFailed::class => 'toolFailed',
        ];
    }

    private function toolName(Tool $tool): string
    {
        return method_exists($tool, 'name') ? (string) $tool->name() : class_basename($tool);
    }
}
