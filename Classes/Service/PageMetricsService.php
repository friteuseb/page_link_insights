<?php

namespace Cywolf\PageLinkInsights\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\Connection;

class PageMetricsService {
    private const TABLE_PAGE_ANALYSIS = 'tx_pagelinkinsights_pageanalysis';
    private const TABLE_LINK_ANALYSIS = 'tx_pagelinkinsights_linkanalysis';
    private const TABLE_STATISTICS = 'tx_pagelinkinsights_statistics';

    /**
     * A full site holds far more page uids than a single IN() should carry.
     * (The INSERTs need no such care: Connection::bulkInsert() splits its own
     * chunks from the platform's bind-parameter limit.)
     */
    private const DELETE_CHUNK_SIZE = 500;

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
        $this->deleteForPages(self::TABLE_PAGE_ANALYSIS, 'page_uid', $pageIds);

        // Clean link analysis data for links originating from pages in this subtree
        $this->deleteForPages(self::TABLE_LINK_ANALYSIS, 'source_page', $pageIds);

        // Clean statistics for this specific site root
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_STATISTICS);
        $queryBuilder
            ->delete(self::TABLE_STATISTICS)
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

        // PageRank needs, for every page, the pages that link to it. Building
        // that adjacency here — in the pass that already walks every link —
        // costs nothing; rebuilding it from the flat link list inside the
        // iteration loop is what made a full-site run never finish (#27).
        $incoming = [];

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

            $incoming[$targetId][] = $sourceId;

            // Broken links
            if ($link['broken']) {
                if (!isset($brokenLinks[$sourceId])) {
                    $brokenLinks[$sourceId] = 0;
                }
                $brokenLinks[$sourceId]++;
            }
        }
        
        // Calculer le PageRank
        $pageRanks = $this->calculatePageRank($nodes, $incoming, $outboundLinks);

        $totalLinks = count($links);

        // Assemble metrics per page
        foreach ($nodes as $node) {
            $pageId = $node['id'];
            $inDegree = $inboundLinks[$pageId] ?? 0;
            $outDegree = $outboundLinks[$pageId] ?? 0;

            $pageMetrics[$pageId] = [
                'page_uid' => (int)$pageId,
                'pagerank' => $pageRanks[$pageId] ?? 0.0,
                'inbound_links' => $inDegree,
                'outbound_links' => $outDegree,
                'broken_links' => $brokenLinks[$pageId] ?? 0,
                // Degree centrality, normalised over the whole link set, read
                // from the counters above instead of scanning the link list
                // twice per page.
                'centrality_score' => $totalLinks > 0
                    ? ($inDegree + $outDegree) / (2 * $totalLinks)
                    : 0.0,
            ];
        }
        
        return $pageMetrics;
    }

    /**
     * @param array<int, array{id: string}> $nodes
     * @param array<string, string[]> $incoming Source page ids, keyed by the page they point at
     * @param array<string, int> $outDegrees Outgoing link count, keyed by page id
     * @return array<string, float>
     */
    private function calculatePageRank(array $nodes, array $incoming, array $outDegrees, float $dampingFactor = 0.85, int $iterations = 20): array {
        $numNodes = count($nodes);
        $pageRank = [];

        if ($numNodes === 0) {
            return $pageRank;
        }

        // Initialisation
        foreach ($nodes as $node) {
            $pageRank[$node['id']] = 1 / $numNodes;
        }

        $base = (1 - $dampingFactor) / $numNodes;
        $nodeIds = array_keys($pageRank);

        // Algorithm iterations
        for ($i = 0; $i < $iterations; $i++) {
            $newRank = [];

            foreach ($nodeIds as $nodeId) {
                $sum = 0.0;

                foreach ($incoming[$nodeId] ?? [] as $sourceId) {
                    $outDegree = $outDegrees[$sourceId] ?? 0;
                    if ($outDegree > 0) {
                        $sum += ($pageRank[$sourceId] ?? 0.0) / $outDegree;
                    }
                }

                $newRank[$nodeId] = $base + $dampingFactor * $sum;
            }

            $pageRank = $newRank;
        }
        
        return $pageRank;
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
        if ($pageMetrics === []) {
            return;
        }

        $currentTime = time();
        $columns = ['pid', 'tstamp', 'crdate', 'page_uid', 'pagerank', 'inbound_links', 'outbound_links', 'broken_links', 'centrality_score'];

        $rows = [];
        foreach ($pageMetrics as $metrics) {
            $rows[] = [
                'pid' => 0,
                'tstamp' => $currentTime,
                'crdate' => $currentTime,
                'page_uid' => (int)$metrics['page_uid'],
                'pagerank' => $metrics['pagerank'],
                'inbound_links' => $metrics['inbound_links'],
                'outbound_links' => $metrics['outbound_links'],
                'broken_links' => $metrics['broken_links'],
                'centrality_score' => $metrics['centrality_score'],
            ];
        }

        // One round-trip per page was the second half of the wait on a large
        // site; batched, a full site is a handful of statements.
        $this->connectionPool
            ->getConnectionForTable(self::TABLE_PAGE_ANALYSIS)
            ->bulkInsert(self::TABLE_PAGE_ANALYSIS, $rows, $columns);
    }
    
    private function persistLinkData(array $links): void {
        if ($links === []) {
            return;
        }

        $currentTime = time();
        $columns = ['pid', 'tstamp', 'crdate', 'source_page', 'target_page', 'content_element', 'link_type', 'is_broken', 'weight'];

        $rows = [];
        foreach ($links as $link) {
            $rows[] = [
                'pid' => 0,
                'tstamp' => $currentTime,
                'crdate' => $currentTime,
                'source_page' => (int)$link['sourcePageId'],
                'target_page' => (int)$link['targetPageId'],
                'content_element' => (int)($link['contentElement']['uid'] ?? 0),
                'link_type' => $link['contentElement']['type'] ?? 'unknown',
                'is_broken' => ($link['broken'] ?? false) ? 1 : 0,
                'weight' => 1.0,
            ];
        }

        $this->connectionPool
            ->getConnectionForTable(self::TABLE_LINK_ANALYSIS)
            ->bulkInsert(self::TABLE_LINK_ANALYSIS, $rows, $columns);
    }
    
    private function persistGlobalStats(array $stats, int $rootPageId): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_STATISTICS);
        $currentTime = time();
        
        $connection->insert(
            self::TABLE_STATISTICS,
            array_merge($stats, [
                'pid' => 0,
                'tstamp' => $currentTime,
                'crdate' => $currentTime,
                'site_root' => $rootPageId
            ])
        );
    }

    /**
     * Clears the rows of the pages about to be rewritten, in batches.
     *
     * @param array<int, string|int> $pageUids
     */
    private function deleteForPages(string $table, string $column, array $pageUids): void
    {
        if ($pageUids === []) {
            return;
        }

        $uids = array_values(array_unique(array_map('intval', $pageUids)));

        foreach (array_chunk($uids, self::DELETE_CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder
                ->delete($table)
                ->where(
                    $queryBuilder->expr()->in(
                        $column,
                        $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)
                    )
                )
                ->executeStatement();
        }
    }
}
