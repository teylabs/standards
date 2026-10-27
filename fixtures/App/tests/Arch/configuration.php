<?php

declare(strict_types=1);

use Tey\Standards\Configuration;

// A fresh app's configuration: generated code must pass every rule here.
return new Configuration(
    base: dirname(__DIR__, 2),
    classRoots: ['app' => 'App'],
    sourceRoots: ['app', 'routes'],
    uiRoots: ['app/UI'],
    uiNamespaces: ['App\\UI'],
    hostNamespaces: ['App'],
    controllerRoots: ['app/Http/Controllers'],
    routeRoots: ['routes'],
    controllerSuffix: 'Controller',
    routeDirectory: 'routes',
    viewModelRoots: ['app/UI'],
    viewModelBases: ['App\\UI\\ViewModel'],
    typedViewModel: 'App\\UI\\ViewModel',
    viewModelSuffix: 'ViewModel',
    pageRoots: ['resources/js/pages'],
    pageScaffolds: ['resources', 'js', 'pages', 'settings'],
    placements: [],
    retired: [],
    discoveryRoots: ['app'],
    developmentNamespaces: ['Pest'],
    safeAttributes: [],
    mediaTrait: 'App\\UI\\HasMediaBehaviour',
    mediaInterface: 'App\\UI\\MediaContract',
    optionalRules: ['ui plus', 'ui new-tab', 'ui dropzone', 'ui toast'],
);
