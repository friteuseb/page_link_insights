<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Cywolf\PageLinkInsights\Task\AnalyzeLinksTask;

defined('TYPO3') or die();

if (isset($GLOBALS['TCA']['tx_scheduler_task'])) {
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
        [
            'label' => 'LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:task.analyzeLinks.title',
            'description' => 'LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:task.analyzeLinks.description',
            'value' => AnalyzeLinksTask::class,
            'group' => 'page_link_insights',
        ],
        '
            --div--;LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:scheduler.form.tabs.general,
                tasktype,
                rootPageId,
            --div--;LLL:EXT:scheduler/Resources/Private/Language/locallang.xlf:scheduler.form.palettes.timing,
                execution_details,
        ',
        [],
        '',
        'tx_scheduler_task'
    );
}
