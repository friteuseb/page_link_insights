<?php

namespace Cywolf\PageLinkInsights\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\Connection;

class PageMetricsService {
    private ConnectionPool $connectionPool;
    private PageLinkService $pageLinkService;
    
    public function __construct(PageLinkService $pageLinkService) {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->pageLinkService = $pageLinkService;
    }

    public function analyzeSite(int $rootPageId): void {
        // Clean old data before inserting new analysis results
        $this->cleanOldAnalysisData($rootPageId);

        // Retrieve link data via the existing service
        $networkData = $this->pageLinkService->getPageLinksForSubtree($rootPageId);

        // Calculate metrics
        $pageMetrics = $this->calculatePageMetrics($networkData);
        $globalStats = $this->calculateGlobalStats($networkData);

        // Save the data
        $this->persistPageMetrics($pageMetrics);
        $this->persistLinkData($networkData['links']);
        $this->persistGlobalStats($globalStats, $rootPageId);
    }

    /**
     * Clean old analysis data before inserting new results
     * This prevents data accumulation when running multiple cron tasks
     */
    private function cleanOldAnalysisData(int $rootPageId): void
    {
        // Get all page IDs in the subtree to clean their specific metrics
        $pageIds = $this->getSubtreePageIds($rootPageId);

        if (empty($pageIds)) {
            return;
        }

        // Clean page analysis data for pages in this subtree
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_pageanalysis');
        $queryBuilder
            ->delete('tx_pagelinkinsights_pageanalysis')
            ->where(
                $queryBuilder->expr()->in(
                    'page_uid',
                    $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeStatement();

        // Clean link analysis data for links originating from pages in this subtree
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_linkanalysis');
        $queryBuilder
            ->delete('tx_pagelinkinsights_linkanalysis')
            ->where(
                $queryBuilder->expr()->in(
                    'source_page',
                    $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeStatement();

        // Clean statistics for this specific site root
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_statistics');
        $queryBuilder
            ->delete('tx_pagelinkinsights_statistics')
            ->where(
                $queryBuilder->expr()->eq(
                    'site_root',
                    $queryBuilder->createNamedParameter($rootPageId, Connection::PARAM_INT)
                )
            )
            ->executeStatement();
    }

    /**
     * Get all page IDs in a subtree
     */
    private function getSubtreePageIds(int $rootPageId): array
    {
        $allPageIds = [$rootPageId];
        $pagesToProcess = [$rootPageId];

        while (!empty($pagesToProcess)) {
            $currentPageIds = $pagesToProcess;
            $pagesToProcess = [];

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $childPages = $queryBuilder
                ->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in(
                        'pid',
                        $queryBuilder->createNamedParameter($currentPageIds, Connection::PARAM_INT_ARRAY)
                    )
                )
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($childPages as $page) {
                $allPageIds[] = $page['uid'];
                $pagesToProcess[] = $page['uid'];
            }
        }

        return $allPageIds;
    }
    
    private function calculatePageMetrics(array $networkData): array {
        $pageMetrics = [];
        $nodes = $networkData['nodes'];
        $links = $networkData['links'];
        
        // Prepare counters
        $inboundLinks = [];
        $outboundLinks = [];
        $brokenLinks = [];
        
        // Compter les liens
        foreach ($links as $link) {
            $sourceId = $link['sourcePageId'];
            $targetId = $link['targetPageId'];
            
            // Liens sortants
            if (!isset($outboundLinks[$sourceId])) {
                $outboundLinks[$sourceId] = 0;
            }
            $outboundLinks[$sourceId]++;
            
            // Liens entrants
            if (!isset($inboundLinks[$targetId])) {
                $inboundLinks[$targetId] = 0;
            }
            $inboundLinks[$targetId]++;
            
            // Broken links
            if ($link['broken']) {
                if (!isset($brokenLinks[$sourceId])) {
                    $brokenLinks[$sourceId] = 0;
                }
                $brokenLinks[$sourceId]++;
            }
        }
        
        // Calculer le PageRank
        $pageRanks = $this->calculatePageRank($nodes, $links);
        
        // Assemble metrics per page
        foreach ($nodes as $node) {
            $pageId = $node['id'];
            $pageMetrics[$pageId] = [
                'page_uid' => (int)$pageId,
                'pagerank' => $pageRanks[$pageId] ?? 0.0,
                'inbound_links' => $inboundLinks[$pageId] ?? 0,
                'outbound_links' => $outboundLinks[$pageId] ?? 0,
                'broken_links' => $brokenLinks[$pageId] ?? 0,
                'centrality_score' => $this->calculateCentrality($pageId, $links)
            ];
        }
        
        return $pageMetrics;
    }
    
    private function calculatePageRank(array $nodes, array $links, float $dampingFactor = 0.85, int $iterations = 20): array {
        $numNodes = count($nodes);
        $pageRank = [];

        if ($numNodes === 0) {
            return $pageRank;
        }

        // Initialisation
        foreach ($nodes as $node) {
            $pageRank[$node['id']] = 1 / $numNodes;
        }
        
        // Algorithm iterations
        for ($i = 0; $i < $iterations; $i++) {
            $newRank = [];
            
            foreach ($nodes as $node) {
                $nodeId = $node['id'];
                $incomingLinks = array_filter($links, fn($link) => $link['targetPageId'] === $nodeId);
                
                $sum = 0;
                foreach ($incomingLinks as $link) {
                    $sourceId = $link['sourcePageId'];
                    $outDegree = count(array_filter($links, fn($l) => $l['sourcePageId'] === $sourceId));
                    if ($outDegree > 0) {
                        $sum += $pageRank[$sourceId] / $outDegree;
                    }
                }
                
                $newRank[$nodeId] = (1 - $dampingFactor) / $numNodes + $dampingFactor * $sum;
            }
            
            $pageRank = $newRank;
        }
        
        return $pageRank;
    }
    
    private function calculateCentrality(string $pageId, array $links): float {
        $totalLinks = count($links);
        if ($totalLinks === 0) {
            return 0.0;
        }

        $inDegree = count(array_filter($links, fn($link) => $link['targetPageId'] === $pageId));
        $outDegree = count(array_filter($links, fn($link) => $link['sourcePageId'] === $pageId));

        return ($inDegree + $outDegree) / (2 * $totalLinks);
    }
    
    private function calculateGlobalStats(array $networkData): array {
        $nodes = $networkData['nodes'];
        $links = $networkData['links'];
        
        $brokenLinks = count(array_filter($links, fn($link) => $link['broken']));
        $totalLinks = count($links);
        $totalPages = count($nodes);
        
        // Calculer les pages orphelines (sans liens entrants)
        $hasIncomingLinks = [];
        foreach ($links as $link) {
            $hasIncomingLinks[$link['targetPageId']] = true;
        }
        $orphanedPages = 0;
        foreach ($nodes as $node) {
            if (!isset($hasIncomingLinks[$node['id']])) {
                $orphanedPages++;
            }
        }
        
        // Calculate network density
        $maxPossibleLinks = $totalPages * ($totalPages - 1);
        $networkDensity = $maxPossibleLinks > 0 ? $totalLinks / $maxPossibleLinks : 0;
        
        return [
            'total_pages' => $totalPages,
            'total_links' => $totalLinks,
            'broken_links_count' => $brokenLinks,
            'orphaned_pages' => $orphanedPages,
            'avg_links_per_page' => $totalPages > 0 ? $totalLinks / $totalPages : 0,
            'network_density' => $networkDensity
        ];
    }
    
    private function persistPageMetrics(array $pageMetrics): void {
        $connection = $this->connectionPool->getConnectionForTable('tx_pagelinkinsights_pageanalysis');
        $currentTime = time();
        
        foreach ($pageMetrics as $metrics) {
            $connection->insert(
                'tx_pagelinkinsights_pageanalysis',
                array_merge($metrics, [
                    'pid' => 0,
                    'tstamp' => $currentTime,
                    'crdate' => $currentTime
                ])
            );
        }
    }
    
    private function persistLinkData(array $links): void {
        $connection = $this->connectionPool->getConnectionForTable('tx_pagelinkinsights_linkanalysis');
        $currentTime = time();
        
        foreach ($links as $link) {
            $connection->insert(
                'tx_pagelinkinsights_linkanalysis',
                [
                    'pid' => 0,
                    'tstamp' => $currentTime,
                    'crdate' => $currentTime,
                    'source_page' => (int)$link['sourcePageId'],
                    'target_page' => (int)$link['targetPageId'],
                    'content_element' => (int)($link['contentElement']['uid'] ?? 0),
                    'link_type' => $link['contentElement']['type'] ?? 'unknown',
                    'is_broken' => ($link['broken'] ?? false) ? 1 : 0,
                    'weight' => 1.0
                ]
            );
        }
    }
    
    private function persistGlobalStats(array $stats, int $rootPageId): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_pagelinkinsights_statistics');
        $currentTime = time();
        
        $connection->insert(
            'tx_pagelinkinsights_statistics',
            array_merge($stats, [
                'pid' => 0,
                'tstamp' => $currentTime,
                'crdate' => $currentTime,
                'site_root' => $rootPageId
            ])
        );
    }
}