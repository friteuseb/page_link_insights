<?php
declare(strict_types=1);

namespace Cwolf\PageLinkInsights\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Doctrine\DBAL\ParameterType;

class PageLinkService
{
    private ConnectionPool $connectionPool;

    public function __construct()
    {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
    }

    public function getPageLinksForSubtree(int $pageUid): array
    {
        $pages = $this->getPageTreeInfo($pageUid);
        if (empty($pages)) {
            return ['nodes' => [], 'links' => []];
        }

        return [
            'nodes' => array_values(array_map(fn($page) => [
                'id' => (string)$page['uid'],
                'title' => $page['title']
            ], $pages)),
            'links' => []
        ];
    }

    private function getPageTreeInfo(int $rootPageId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        return $queryBuilder
            ->select('uid', 'pid', 'title', 'doktype')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('uid', 
                        $queryBuilder->createNamedParameter($rootPageId, ParameterType::INTEGER)
                    ),
                    $queryBuilder->expr()->eq('pid', 
                        $queryBuilder->createNamedParameter($rootPageId, ParameterType::INTEGER)
                    )
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }
}