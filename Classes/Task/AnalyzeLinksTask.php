<?php

namespace Cywolf\PageLinkInsights\Task;

use TYPO3\CMS\Core\Log\LogManager;
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
        } catch (\Throwable $e) {
            // Returning false alone leaves the scheduler showing nothing but
            // its generic "task returned false", which made a timeout, an
            // exhausted memory limit and a genuine bug indistinguishable (#27).
            // \Throwable rather than \Exception: an \Error — a division by
            // zero on a subtree without links, say — went straight past the old
            // catch and surfaced as an uncaught fatal.
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(__CLASS__)
                ->error(
                    'Link analysis failed for root page {rootPageId}: {message}',
                    [
                        'rootPageId' => $this->rootPageId,
                        'message' => $e->getMessage(),
                        'exception' => $e,
                    ]
                );

            return false;
        }
    }

    public function getAdditionalInformation(): string
    {
        return 'Analyze links for root page: ' . $this->rootPageId;
    }
}