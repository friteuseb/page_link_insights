<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Cywolf\PageLinkInsights\Task\AnalyzeLinksTask;

defined('TYPO3') or die();

// Add custom columns for the scheduler task
ExtensionManagementUtility::addTCAcolumns(
    'tx_scheduler_task',
    [
        'rootPageId' => [
            'label' => 'LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:task.field.rootPageId',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'default' => 1,
                'required' => true,
            ],
        ],
    ]
);

// Register the task type with TCA
ExtensionManagementUtility::addRecordType(
    AnalyzeLinksTask::class,
    [
        'label' => 'LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:task.analyzeLinks.title',
        'description' => 'LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:task.analyzeLinks.description',
        'showitem' => '--div--;LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:tx_scheduler_task.basicConfiguration, rootPageId',
    ],
    'tx_scheduler_task'
);
