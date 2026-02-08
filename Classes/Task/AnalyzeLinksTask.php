<?php

namespace Cywolf\PageLinkInsights\Task;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use Cywolf\PageLinkInsights\Service\PageMetricsService;


class AnalyzeLinksTask extends AbstractTask
{
    public int $rootPageId = 1;  // Default root page

    public function execute(): bool
    {
        try {
            /** @var PageMetricsService $metricsService */
            $metricsService = GeneralUtility::makeInstance(PageMetricsService::class);
            $metricsService->analyzeSite($this->rootPageId);

            return true;
        } catch (\Exception $e) {
            // Log the error
            return false;
        }
    }

    public function getAdditionalInformation(): string
    {
        return 'Analyze links for root page: ' . $this->rootPageId;
    }
}