<?php

use Tey\Standards\Configuration;

return new Configuration(
    base: dirname(__DIR__, 2),
    classRoots: ['app' => 'App'],
    sourceRoots: ['app'],
    uiRoots: [],
    uiNamespaces: [],
    hostNamespaces: ['App'],
    controllerRoots: ['app/Http/Controllers'],
    routeRoots: ['routes'],
    controllerSuffix: 'Controller',
    routeDirectory: 'routes',
    viewModelRoots: [],
    viewModelBases: [],
    typedViewModel: '',
    viewModelSuffix: 'ViewModel',
    pageRoots: ['resources/js/pages'],
    pageScaffolds: [],
    placements: [],
    retired: [],
    discoveryRoots: ['app'],
    developmentNamespaces: [],
    safeAttributes: [],
    mediaTrait: 'App\\Media\\HasMedia',
    mediaInterface: 'App\\Media\\MediaContract',
    allowlist: [
        ['rule' => 'adoption.tey/kit.layout.page', 'target' => 'layout.page', 'check' => 'adoption', 'reason' => 'The top bar moves to the kit shell in the next pass.', 'date' => '2026-10-01', 'expires' => '2099-12-31'],
        ['rule' => 'adoption.gap', 'target' => 'resources/js/components/Modal.vue', 'check' => 'adoption', 'reason' => 'Kit has no non-modal dialog yet.', 'date' => '2026-10-01', 'expires' => '2099-12-31'],
        ['rule' => 'adoption.tey/kit.data.table', 'target' => 'data.table', 'check' => 'adoption', 'reason' => 'Waiting on the index rebuild.', 'date' => '2026-10-01', 'expires' => '2026-10-15'],
        ['rule' => 'adoption.tey/kit.mail.layout', 'target' => 'mail.layout', 'check' => 'adoption', 'reason' => 'Mail is being redesigned.', 'date' => '2026-10-01'],
        ['rule' => 'adoption.tey/kit.workspaces.tenancy', 'target' => 'workspaces.tenancy', 'check' => 'adoption', 'reason' => 'Workspaces land later.', 'date' => '2026-10-01', 'expires' => '2099-12-31'],
    ],
);
