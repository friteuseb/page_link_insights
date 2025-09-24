<?php

namespace Cywolf\PageLinkInsights\Task;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Scheduler\AbstractAdditionalFieldProvider;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use TYPO3\CMS\Core\Messaging\FlashMessage;

class AnalyzeLinksTaskAdditionalFieldProvider extends AbstractAdditionalFieldProvider
{
    public function getAdditionalFields(array &$taskInfo, $task, SchedulerModuleController $schedulerModule): array
    {
        $additionalFields = [];
        
        // Initialize the value either from the existing task or with the default value
        if ($task instanceof AnalyzeLinksTask) {
            $taskInfo['rootPageId'] = $task->rootPageId;
        } else {
            $taskInfo['rootPageId'] = 1; // Default value
        }

        $fieldId = 'task_rootPageId';
        $fieldCode = '<input type="number" class="form-control" name="tx_scheduler[rootPageId]" id="' . $fieldId . '" value="' . ($taskInfo['rootPageId'] ?? 1) . '" />';
        
        $additionalFields[$fieldId] = [
            'code' => $fieldCode,
            'label' => 'LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:task.field.rootPageId',
            'cshKey' => '_MOD_tools_txschedulerM1',
            'cshLabel' => $fieldId
        ];
        
        return $additionalFields;
    }

    public function validateAdditionalFields(array &$submittedData, SchedulerModuleController $schedulerModule): bool
    {
        $rootPageId = (int)($submittedData['rootPageId'] ?? 0);
        
        if ($rootPageId <= 0) {
            $schedulerModule->addMessage(
                $this->getLanguageService()->sL('LLL:EXT:page_link_insights/Resources/Private/Language/locallang.xlf:task.error.invalidRootPage'),
                FlashMessage::ERROR
            );
            return false;
        }
        
        return true;
    }

    public function saveAdditionalFields(array $submittedData, AbstractTask $task): void
    {
        if ($task instanceof AnalyzeLinksTask) {
            $task->rootPageId = (int)($submittedData['rootPageId'] ?? 1);
        }
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}