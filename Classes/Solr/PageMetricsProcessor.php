<?php

namespace Cywolf\PageLinkInsights\Solr;

use ApacheSolrForTypo3\Solr\IndexQueue\AbstractDataProcessor;
use ApacheSolrForTypo3\Solr\IndexQueue\Item;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PageMetricsProcessor extends AbstractDataProcessor
{
    public function processPageRecord(Item $item, array $record, array $solrConfiguration): array
    {
        $pageUid = $record['uid'];
        
        // Récupérer les métriques de la page
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_pagelinkinsights_pageanalysis');
            
        $metrics = $queryBuilder
            ->select('*')
            ->from('tx_pagelinkinsights_pageanalysis')
            ->where(
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid))
            )
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
            
        if ($metrics) {
            // Ajouter les métriques au document Solr
            $record['pagerank_f'] = (float)$metrics['pagerank'];
            $record['inbound_links_i'] = (int)$metrics['inbound_links'];
            $record['centrality_f'] = (float)$metrics['centrality_score'];
        }
        
        return $record;
    }
}