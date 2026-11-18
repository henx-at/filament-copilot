<?php

declare(strict_types=1);

namespace EslamRedaDiv\FilamentCopilot\Services;

use Closure;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Resolves the Filament page (and the record on it) the user had open when
 * sending a chat message, from the `current_url` the chat posts, and renders
 * it as a system-prompt section. Lets the model resolve "this", "here" etc.
 *
 * Fail-closed on records: the stream request does not boot the panel, so the
 * record is looked up through the resource with the tenant scope applied
 * explicitly and must pass canView(); otherwise only the page is reported.
 */
class PageContext
{
    /**
     * @param  (Closure(Model, class-string<resource>): ?string)|null  $describeRecord  title of the record, or null to hide it
     * @return array{url: string, page: string, page_class: class-string<Page>, resource: ?string, model: ?class-string<Model>, record_id: ?string, record_title: ?string, is_create: bool, is_list: bool}|null
     */
    public static function resolve(?string $url, string $panelId, ?Closure $describeRecord = null): ?array
    {
        $path = is_string($url) ? (string) parse_url($url, PHP_URL_PATH) : '';

        if ($path === '') {
            return null;
        }

        $panelPath = Filament::getPanel($panelId)->getPath();
        $pos = $panelPath !== '' ? strpos($path, "/{$panelPath}") : 0;

        if ($pos === false) {
            return null;
        }

        $appPath = substr($path, $pos);

        try {
            $route = Route::getRoutes()->match(Request::create($appPath, 'GET'));
        } catch (Throwable) {
            return null;
        }

        $pageClass = $route->getActionName();

        if (! class_exists($pageClass) || ! is_subclass_of($pageClass, Page::class)) {
            return null;
        }

        $resourceClass = method_exists($pageClass, 'getResource') ? $pageClass::getResource() : null;
        $isResource = is_string($resourceClass) && is_subclass_of($resourceClass, Resource::class);

        // Name prefix as fallback for custom resource pages that extend Page directly.
        $base = class_basename($pageClass);
        $isCreate = is_subclass_of($pageClass, CreateRecord::class) || str_starts_with($base, 'Create');
        $isList = is_subclass_of($pageClass, ListRecords::class) || str_starts_with($base, 'List');
        $isEdit = is_subclass_of($pageClass, EditRecord::class) || str_starts_with($base, 'Edit');
        $isView = is_subclass_of($pageClass, ViewRecord::class) || str_starts_with($base, 'View');

        $recordId = null;
        $recordTitle = null;
        $routeKey = (string) ($route->parameter('record') ?? '');

        if ($routeKey !== '' && $isResource) {
            try {
                $record = static::resolveRecord($resourceClass, $routeKey);

                if ($record !== null && $resourceClass::canView($record)) {
                    $recordTitle = $describeRecord !== null
                        ? $describeRecord($record, $resourceClass)
                        : (string) $resourceClass::getRecordTitle($record);

                    // A describer returning null hides the record (e.g. extra tenant rules).
                    $recordId = $recordTitle === null ? null : (string) $record->getKey();
                }
            } catch (Throwable) {
                $recordId = null;
                $recordTitle = null;
            }
        }

        return [
            'url' => $appPath,
            'page' => static::pageName($pageClass, $isResource ? $resourceClass : null, $isCreate, $isList, $isEdit, $isView),
            'page_class' => $pageClass,
            'resource' => $isResource ? (string) $resourceClass::getModelLabel() : null,
            'model' => $isResource ? $resourceClass::getModel() : null,
            'record_id' => $recordId,
            'record_title' => $recordTitle,
            'is_create' => $isCreate,
            'is_list' => $isList,
        ];
    }

    /**
     * @param  (Closure(Model, class-string<resource>): ?string)|null  $describeRecord
     */
    public static function prompt(?string $url, string $panelId, ?Closure $describeRecord = null): ?string
    {
        $context = static::resolve($url, $panelId, $describeRecord);

        if ($context === null) {
            return null;
        }

        $lines = [
            '## Current browser context (open page / record)',
            "- URL: {$context['url']}",
            "- Page: {$context['page']} ({$context['page_class']})",
        ];

        $tenant = Filament::getTenant();

        if ($tenant !== null) {
            $lines[] = '- Tenant: "'.Filament::getTenantName($tenant).'" (ID '.$tenant->getKey().'); tools already work inside it.';
        }

        if ($context['resource'] !== null) {
            $lines[] = "- Resource: {$context['resource']} (Model: {$context['model']})";
        }

        $lines[] = match (true) {
            $context['record_id'] !== null => "- Open record: ID {$context['record_id']}".(filled($context['record_title']) ? " ({$context['record_title']})" : ''),
            $context['is_create'] => '- Open record: a new record being created',
            $context['is_list'] => '- Open record: none (list view)',
            default => '- Open record: none',
        };

        $lines[] = 'References like "this", "here", "the record" mean the open record above.';

        return implode("\n", $lines);
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    protected static function resolveRecord(string $resourceClass, string $routeKey): ?Model
    {
        $tenant = Filament::getTenant();

        return $resourceClass::resolveRecordRouteBinding(
            $routeKey,
            fn (Builder $query): Builder => $tenant !== null && $resourceClass::isScopedToTenant()
                ? $resourceClass::scopeEloquentQueryToTenant($query, $tenant)
                : $query,
        );
    }

    /**
     * @param  class-string<Page>  $pageClass
     * @param  class-string<resource>|null  $resourceClass
     */
    protected static function pageName(string $pageClass, ?string $resourceClass, bool $isCreate, bool $isList, bool $isEdit, bool $isView): string
    {
        if ($resourceClass !== null) {
            $label = (string) $resourceClass::getModelLabel();

            return match (true) {
                $isCreate => "{$label}: create",
                $isEdit => "{$label}: edit",
                $isView => "{$label}: view",
                $isList => (string) $resourceClass::getPluralModelLabel().': list',
                default => (string) ($pageClass::getNavigationLabel() ?: class_basename($pageClass)),
            };
        }

        return (string) ($pageClass::getNavigationLabel() ?: class_basename($pageClass));
    }
}
