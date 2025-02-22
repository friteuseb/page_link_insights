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
        // Récupérer les données de liens via le service existant
        $networkData = $this->pageLinkService->getPageLinksForSubtree($rootPageId);
        
        // Calculer les métriques
        $pageMetrics = $this->calculatePageMetrics($networkData);
        $globalStats = $this->calculateGlobalStats($networkData);
        
        // Sauvegarder les données
        $this->persistPageMetrics($pageMetrics);
        $this->persistLinkData($networkData['links']);
        $this->persistGlobalStats($globalStats);
    }
    
    private function calculatePageMetrics(array $networkData): array {
        $pageMetrics = [];
        $nodes = $networkData['nodes'];
        $links = $networkData['links'];
        
        // Préparer les compteurs
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
            
            // Liens cassés
            if ($link['broken']) {
                if (!isset($brokenLinks[$sourceId])) {
                    $brokenLinks[$sourceId] = 0;
                }
                $brokenLinks[$sourceId]++;
            }
        }
        
        // Calculer le PageRank
        $pageRanks = $this->calculatePageRank($nodes, $links);
        
        // Assembler les métriques par page
        foreach ($nodes as $node) {
            $pageId = $node['id'];
            $pageMetrics[$pageId] = [
                'page_uid' => $pageId,
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
        
        // Initialisation
        foreach ($nodes as $node) {
            $pageRank[$node['id']] = 1 / $numNodes;
        }
        
        // Itérations de l'algorithme
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
        $inDegree = count(array_filter($links, fn($link) => $link['targetPageId'] === $pageId));
        $outDegree = count(array_filter($links, fn($link) => $link['sourcePageId'] === $pageId));
        
        return ($inDegree + $outDegree) / (2 * count($links));
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
        
        // Calculer la densité du réseau
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
                    'source_page' => $link['sourcePageId'],
                    'target_page' => $link['targetPageId'],
                    'content_element' => $link['contentElement']['uid'],
                    'link_type' => $link['contentElement']['type'],
                    'is_broken' => $link['broken'] ? 1 : 0,
                    'weight' => 1.0
                ]
            );
        }
    }
    
    private function persistGlobalStats(array $stats): void {
        $connection = $this->connectionPool->getConnectionForTable('tx_pagelinkinsights_statistics');
        $currentTime = time();
        
        $connection->insert(
            'tx_pagelinkinsights_statistics',
            array_merge($stats, [
                'pid' => 0,
                'tstamp' => $currentTime,
                'crdate' => $currentTime
            ])
        );
    }
}