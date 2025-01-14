<?php

return [
    'tools_page_link_insights' => [
        'parent' => 'tools',
        'position' => ['after' => 'tools_install'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/tools/pagelinkinsights',
        'labels' => 'LLL:EXT:page_link_insights/Resources/Private/Language/locallang_mod.xlf',
        'iconIdentifier' => 'module-page-link-insights',
        'extensionName' => 'PageLinkInsights',
        'controllerActions' => [
            \Cwolf\PageLinkInsights\Controller\BackendController::class => [
                'main'
            ],
        ],
        // Configuration module
        'moduleData' => [
            'name' => 'tools_page_link_insights',
            'packageName' => 'page_link_insights'
        ],
    ],
];