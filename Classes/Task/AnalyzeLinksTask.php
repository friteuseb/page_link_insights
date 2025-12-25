<?php

declare(strict_types=1);

namespace Cywolf\PageLinkInsights\Task;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use Cywolf\PageLinkInsights\Service\PageMetricsService;
use Cywolf\PageLinkInsights\Service\ThemeDataService;
use TYPO3\CMS\Core\Database\ConnectionPool;

class AnalyzeLinksTask extends AbstractTask
{
    public int $rootPageId = 1;

    public function execute(): bool
    {
        try {
            /** @var PageMetricsService $metricsService */
            $metricsService = GeneralUtility::makeInstance(PageMetricsService::class);
            $metricsService->analyzeSite($this->rootPageId);

            /** @var ThemeDataService $themeService */
            $themeService = GeneralUtility::makeInstance(ThemeDataService::class);

            $this->cleanOldThemeData();
            $themeService->analyzePageContent($this->rootPageId);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function cleanOldThemeData(): void
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        $threshold = time() - (30 * 24 * 60 * 60);

        $tables = [
            'tx_pagelinkinsights_keywords',
            'tx_pagelinkinsights_themes',
            'tx_pagelinkinsights_page_themes'
        ];

        foreach ($tables as $table) {
            $queryBuilder = $connectionPool->getQueryBuilderForTable($table);
            $queryBuilder
                ->delete($table)
                ->where(
                    $queryBuilder->expr()->lt(
                        'tstamp',
                        $queryBuilder->createNamedParameter($threshold)
                    )
                )
                ->executeStatement();
        }
    }

    public function getAdditionalInformation(): string
    {
        return 'Analyze links and themes for root page: ' . $this->rootPageId;
    }

    /**
     * Set task parameters from TCA form (TYPO3 v14+)
     */
    public function setTaskParameters(array $parameters): void
    {
        if (isset($parameters['rootPageId'])) {
            $this->rootPageId = (int)$parameters['rootPageId'];
        }
    }

    /**
     * Get task parameters for TCA migration (TYPO3 v14+)
     */
    public function getTaskParameters(): array
    {
        return [
            'rootPageId' => $this->rootPageId,
        ];
    }

    /**
     * Validate task parameters (TYPO3 v14+)
     */
    public function validateTaskParameters(array $parameters): bool
    {
        $rootPageId = (int)($parameters['rootPageId'] ?? 0);
        return $rootPageId > 0;
    }
}
