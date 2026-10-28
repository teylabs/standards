<?php

declare(strict_types=1);

use Tey\Standards\Configuration;

// A modular app's configuration (a tey/mod layout extending `modules`, with view models in a `ui` mount at app/UI/<Module>/ViewModels): code standards:make
// places in its modules must pass every rule here.
return new Configuration(
    base: dirname(__DIR__, 2),
    classRoots: ['app' => 'App'],
    sourceRoots: ['app', 'routes'],
    uiRoots: ['app/UI/Components'],
    uiNamespaces: ['App\\UI\\Components'],
    hostNamespaces: ['App'],
    controllerRoots: ['app/Http/Controllers', 'app/Modules/*/Http/Controllers'],
    routeRoots: ['routes', 'app/Modules/*/routes'],
    controllerSuffix: 'Controller',
    routeDirectory: 'routes',
    viewModelRoots: ['app/UI/*/ViewModels', 'app/Modules/*/ViewModels'],
    viewModelBases: ['App\\UI\\ViewModel'],
    typedViewModel: 'App\\UI\\ViewModel',
    viewModelSuffix: 'ViewModel',
    pageRoots: ['resources/js/pages', 'app/Modules/*/resources/js/pages'],
    pageScaffolds: ['app', 'resources', 'js', 'pages', 'settings'],
    placements: [],
    retired: [],
    discoveryRoots: ['app'],
    developmentNamespaces: ['Pest'],
    safeAttributes: [],
    mediaTrait: 'App\\UI\\HasMediaBehaviour',
    mediaInterface: 'App\\UI\\MediaContract',
    optionalRules: ['ui plus', 'ui new-tab', 'ui dropzone', 'ui toast'],
);
