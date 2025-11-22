<?php

namespace Cywolf\PageLinkInsights\Task;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use Cywolf\PageLinkInsights\Service\PageMetricsService;
use Cywolf\PageLinkInsights\Service\ThemeDataService; 


class AnalyzeLinksTask extends AbstractTask
{
    public int $rootPageId = 1;  // Default root page
    
    public function execute(): bool
    {
        try {
            // Link analysis - cleaning is now handled inside the service
            /** @var PageMetricsService $metricsService */
            $metricsService = GeneralUtility::makeInstance(PageMetricsService::class);
            $metricsService->analyzeSite($this->rootPageId);

            // Thematic analysis - cleaning is now handled inside the service
            /** @var ThemeDataService $themeService */
            $themeService = GeneralUtility::makeInstance(ThemeDataService::class);
            $themeService->analyzePageContent($this->rootPageId);

            return true;
        } catch (\Exception $e) {
            // Log the error
            return false;
        }
    }
    
    public function getAdditionalInformation(): string
    {
        return 'Analyze links and themes for root page: ' . $this->rootPageId;
    }
}