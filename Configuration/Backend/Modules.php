<?php

return [
    'web_page_link_insights' => [
        'parent' => 'web',
        'position' => ['after' => 'web_layout'],
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/web/page-link-insights',
        'labels' => 'LLL:EXT:page_link_insights/Resources/Private/Language/locallang_mod.xlf',
        'iconIdentifier' => 'module-page-link-insights',
        'navigationComponent' => '@typo3/backend/tree/page-tree-element',
        'extensionName' => 'PageLinkInsights',
        'controllerActions' => [
            \Cywolf\PageLinkInsights\Controller\BackendController::class => [
                'main'
            ],
        ],
    ],
];
