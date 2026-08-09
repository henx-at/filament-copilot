<?php

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;

it('registers copilot assets as local package paths', function () {
    $styles = FilamentAsset::getStyles(['eslam-reda-div/filament-copilot']);
    $scripts = FilamentAsset::getScripts(['eslam-reda-div/filament-copilot'], withCore: false);

    expect($styles)->toHaveCount(1)
        ->and($styles[0])->toBeInstanceOf(Css::class)
        ->and($styles[0]->isRemote())->toBeFalse()
        ->and($styles[0]->getPath())->toEndWith('/resources/dist/filament-copilot.css');

    expect($scripts)->toHaveCount(1)
        ->and($scripts[0])->toBeInstanceOf(Js::class)
        ->and($scripts[0]->isRemote())->toBeFalse()
        ->and($scripts[0]->getPath())->toEndWith('/resources/dist/filament-copilot.js');
});
