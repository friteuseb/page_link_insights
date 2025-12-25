<?php

declare(strict_types=1);

namespace Cywolf\PageLinkInsights\Event;

use ApacheSolrForTypo3\Solr\Event\Indexing\AfterPageDocumentIsCreatedForIndexingEvent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class PageMetricsEventListener
 *
 * @package \Pgu\Solr\Event
 */
class PageMetricsEventListener
{
    public function __invoke(AfterPageDocumentIsCreatedForIndexingEvent $event): void
    {
        $substitutePageDocument = clone $event->getDocument();

        $dataFIelds = $event->getDocument()->getFields();

        if($dataFIelds['type'] == 'pages'){
            $pageUid = (int)$dataFIelds['uid'];

            // Retrieve the page metrics
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

            if($metrics)    {
                $substitutePageDocument->setField('pagerank_floatS', (float)$metrics['pagerank']);
                $substitutePageDocument->addField('inbound_links_intS', (int)$metrics['inbound_links']);
                $substitutePageDocument->addField('centrality_floatS', (float)$metrics['centrality_score']);
                $event->overrideDocument($substitutePageDocument);
            }
        }
    }
}
