<?php
declare(strict_types=1);

namespace Cywolf\PageLinkInsights\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Connection;

class PageLinkService
{
    private ConnectionPool $connectionPool;
    private array $extensionConfiguration;
    private array $allowedColPos;
    private bool $includeHidden;
    private bool $includeShortcuts;
    private bool $includeExternalLinks;
    private bool $includeSemanticSuggestions;

    public function __construct()
    {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        
        // Retrieve the extension configuration
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('page_link_insights');
            
        // Convertir les colPos en tableau d'entiers
        $this->allowedColPos = GeneralUtility::intExplode(',', $this->extensionConfiguration['colPosToAnalyze'] ?? '0', true);
        $this->includeHidden = (bool)($this->extensionConfiguration['includeHidden'] ?? false);
        $this->includeShortcuts = (bool)($this->extensionConfiguration['includeShortcuts'] ?? false);
        $this->includeExternalLinks = (bool)($this->extensionConfiguration['includeExternalLinks'] ?? false);
        $this->includeSemanticSuggestions = (bool)($this->extensionConfiguration['includeSemanticSuggestions'] ?? true);
    }

    private function getExcludedDokTypes(): array
    {
        $excludedDokTypes = [
            254, // System folders
            255, // Recycler (legacy)
            199  // Menu separators - always exclude as they don't serve content
        ];

        // Conditionally exclude shortcuts and external links
        if (!$this->includeShortcuts) {
            $excludedDokTypes[] = 4; // Shortcuts
        }

        if (!$this->includeExternalLinks) {
            $excludedDokTypes[] = 3; // External links
        }

        return $excludedDokTypes;
    }

    public function getPageLinksForSubtree(int $pageUid): array
    {
        error_log("Starting getPageLinksForSubtree with pageUid: $pageUid");
        
        // Get all pages in the subtree
        $pages = $this->getPageTreeInfo($pageUid);
        error_log("Found initial pages: " . json_encode($pages));
        
        if (empty($pages)) {
            return ['nodes' => [], 'links' => []];
        }
    
        // Get all content elements and their links from all pages in the subtree
        $pageUids = array_column($pages, 'uid');
        $allLinks = [];
        
        // Get direct content links
        $contentLinks = $this->getContentElementLinks($pageUids);
        $allLinks = array_merge($allLinks, $contentLinks);
        
        // Collect all page UIDs referenced in links
        $referencedPageIds = [];
        foreach ($allLinks as $link) {
            $referencedPageIds[] = $link['sourcePageId'];
            $referencedPageIds[] = $link['targetPageId'];
        }
        $referencedPageIds = array_unique($referencedPageIds);
        
        // Get additional page information for referenced pages
        $missingPageIds = array_diff($referencedPageIds, $pageUids);
        if (!empty($missingPageIds)) {
            $additionalPages = $this->getAdditionalPagesInfo($missingPageIds);
            $pages = array_merge($pages, $additionalPages);
        }
    
        // Mark broken links
        $pageIds = array_column($pages, 'uid');
        $allLinks = array_map(function($link) use ($pageIds) {
            $link['broken'] = !in_array($link['sourcePageId'], $pageIds) || !in_array($link['targetPageId'], $pageIds);
            return $link;
        }, $allLinks);
    
        // Add logging for broken links
        $brokenLinks = array_filter($allLinks, function($link) use ($pageIds) {
            return !in_array($link['sourcePageId'], $pageIds) || !in_array($link['targetPageId'], $pageIds);
        });
    
        if (!empty($brokenLinks)) {
            error_log("Broken links found: " . json_encode($brokenLinks));
        }
    
        return [
            'nodes' => array_values(array_map(fn($page) => [
                'id' => (string)$page['uid'],
                'title' => $page['title']
            ], $pages)),
            'links' => $allLinks
        ];
    }

    private function getSemanticSuggestionLinks(array $pageUids): array
    {
        $links = [];

        if (empty($pageUids) || !$this->shouldIncludeSemanticSuggestions()) {
            return $links;
        }

        // Get semantic_suggestion configuration to match frontend display
        $semanticConfig = $this->getSemanticSuggestionConfig();
        $maxSuggestions = $semanticConfig['maxSuggestions'];
        $proximityThreshold = $semanticConfig['proximityThreshold'];

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_semanticsuggestion_similarities');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        $similarities = $queryBuilder
            ->select('page_id', 'similar_page_id', 'similarity_score')
            ->from('tx_semanticsuggestion_similarities')
            ->where(
                $queryBuilder->expr()->in(
                    'page_id',
                    $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->gte(
                    'similarity_score',
                    $queryBuilder->createNamedParameter($proximityThreshold, ParameterType::STRING)
                )
            )
            ->orderBy('page_id', 'ASC')
            ->addOrderBy('similarity_score', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        // Limit suggestions per page (like frontend does)
        $suggestionsPerPage = [];
        foreach ($similarities as $similarity) {
            $pageId = (int)$similarity['page_id'];

            if (!isset($suggestionsPerPage[$pageId])) {
                $suggestionsPerPage[$pageId] = 0;
            }

            // Only include up to maxSuggestions per page (matching frontend behavior)
            if ($suggestionsPerPage[$pageId] >= $maxSuggestions) {
                continue;
            }

            $suggestionsPerPage[$pageId]++;

            $links[] = [
                'sourcePageId' => (string)$similarity['page_id'],
                'targetPageId' => (string)$similarity['similar_page_id'],
                'contentElement' => [
                    'uid' => 0,
                    'type' => 'semantic_suggestion',
                    'header' => 'Semantic Suggestion',
                    'colPos' => -1
                ],
                'similarity' => $similarity['similarity_score'],
                'isSemantic' => true
            ];
        }

        return $links;
    }

    /**
     * Get semantic_suggestion extension configuration
     */
    private function getSemanticSuggestionConfig(): array
    {
        try {
            $config = GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('semantic_suggestion');

            return [
                'maxSuggestions' => (int)($config['settings.maxSuggestions'] ?? $config['maxSuggestions'] ?? 5),
                'proximityThreshold' => (float)($config['settings.proximityThreshold'] ?? $config['proximityThreshold'] ?? 0.3),
            ];
        } catch (\Exception $e) {
            // Default values if config not available
            return [
                'maxSuggestions' => 5,
                'proximityThreshold' => 0.3,
            ];
        }
    }

    /**
     * Check if semantic suggestions should be included based on both extension availability and configuration
     */
    public function shouldIncludeSemanticSuggestions(): bool
    {
        return \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('semantic_suggestion')
            && $this->includeSemanticSuggestions;
    }

    private function getMenuSitemapPages(array $pageUids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());
            
        if (!$this->includeHidden) {
            $queryBuilder->getRestrictions()->add(new HiddenRestriction());
        }

        return $queryBuilder
            ->select('uid', 'pid', 'CType')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->in(
                    'CType',
                    $queryBuilder->createNamedParameter(['menu_sitemap', 'menu_sitemap_pages'], Connection::PARAM_STR_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

private function getPageTreeInfo(int $rootPageId): array
{
    $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
    $queryBuilder->getRestrictions()
        ->removeAll()
        ->add(new DeletedRestriction());
        
    if (!$this->includeHidden) {
        $queryBuilder->getRestrictions()->add(new HiddenRestriction());
    }

    // Récupérer la page racine
    $rootPage = $queryBuilder
        ->select('uid', 'pid', 'title', 'doktype')
        ->from('pages')
        ->where(
            $queryBuilder->expr()->eq('uid', 
                $queryBuilder->createNamedParameter($rootPageId, Connection::PARAM_INT)
            ),
            // Exclure les types de pages non-content (menu separators, optionally shortcuts/links)
            $queryBuilder->expr()->notIn(
                'doktype',
                $queryBuilder->createNamedParameter($this->getExcludedDokTypes(), Connection::PARAM_INT_ARRAY)
            ),
            $queryBuilder->expr()->eq('sys_language_uid', 0) // Filter on default language
        )
        ->executeQuery()
        ->fetchAssociative();

    if (!$rootPage) {
        return [];
    }

    $allPages = [$rootPage];
    $pagesToProcess = [$rootPageId];

        // Traverse the tree recursively
    while (!empty($pagesToProcess)) {
        $currentPageIds = $pagesToProcess;
        $pagesToProcess = [];

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());
            
        if (!$this->includeHidden) {
            $queryBuilder->getRestrictions()->add(new HiddenRestriction());
        }

        $childPages = $queryBuilder
            ->select('uid', 'pid', 'title', 'doktype')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($currentPageIds, Connection::PARAM_INT_ARRAY)
                ),
                // Exclure les types de pages non-content (menu separators, optionally shortcuts/links)
                $queryBuilder->expr()->notIn(
                    'doktype',
                    $queryBuilder->createNamedParameter($this->getExcludedDokTypes(), Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->eq('sys_language_uid', 0) // Filter on default language
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($childPages as $page) {
            $allPages[] = $page;
            $pagesToProcess[] = $page['uid'];
        }
    }

    return $allPages;
}
    
    private function getAdditionalPagesInfo(array $pageIds): array
    {
        if (empty($pageIds)) {
            return [];
        }
    
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());
            
        if (!$this->includeHidden) {
            $queryBuilder->getRestrictions()->add(new HiddenRestriction());
        }
    
        return $queryBuilder
            ->select('uid', 'pid', 'title', 'doktype')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter(
                        array_map('intval', $pageIds),
                        \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY
                    )
                ),
                // Exclure les types de pages non-content (menu separators, optionally shortcuts/links)
                $queryBuilder->expr()->notIn(
                    'doktype',
                    $queryBuilder->createNamedParameter($this->getExcludedDokTypes(), Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->eq('sys_language_uid', 0) // Filter on default language
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function getSubPages(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());
            
        if (!$this->includeHidden) {
            $queryBuilder->getRestrictions()->add(new HiddenRestriction());
        }

        return $queryBuilder
            ->select('uid', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function getContentElementLinks(array $pageUids): array
    {
        $links = [];
        
        if (empty($pageUids)) {
            return $links;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());
            
        if (!$this->includeHidden) {
            $queryBuilder->getRestrictions()->add(new HiddenRestriction());
        }

        // Retrieve all fields that can contain links
        $contentElements = $queryBuilder
            ->select('*')  // We retrieve all fields to miss nothing
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->in(
                    'colPos',
                    $queryBuilder->createNamedParameter($this->allowedColPos, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($contentElements as $content) {
            // Analyser chaque champ du contenu pour trouver des liens
            foreach ($content as $fieldName => $fieldValue) {
                if (is_string($fieldValue) && !empty($fieldValue)) {
                    // Check links in the text
                    $this->processTextLinks($fieldValue, $content, $links);
                }
            }

            // Special processing for contents with referenced pages
            if (str_starts_with($content['CType'], 'menu_') || 
                !empty($content['pages']) || 
                str_contains($content['CType'], 'list')) {
                $this->processMenuElement($content, $links);
            }
        }

            // Add semantic suggestion links
            if ($this->shouldIncludeSemanticSuggestions()) {
                $semanticLinks = $this->getSemanticSuggestionLinks($pageUids);
                $links = array_merge($links, $semanticLinks);
            }

            return $links;
        }

    private function processTextLinks(string $content, array $element, array &$links): void
    {
        // t3://page links
        if (preg_match_all('/t3:\/\/page\?uid=(\d+)/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $this->addLink($element, (string)$pageUid, $links);
            }
        }

        // <link> tags
        if (preg_match_all('/<link (\d+)>/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $this->addLink($element, (string)$pageUid, $links);
            }
        }
        
        // Legacy typolinks
        if (preg_match_all('/\b(?:t3:\/\/)?page,(\d+)(?:,|\s|$)/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $this->addLink($element, (string)$pageUid, $links);
            }
        }
        
        // record:pages:UID links
        if (preg_match_all('/record:pages:(\d+)/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $this->addLink($element, (string)$pageUid, $links);
            }
        }
        
        // Direct links to pages (used in some plugins)
        if (preg_match_all('/(?:^|[^\d])(\d+)(?:[^\d]|$)/', $content, $matches)) {
            foreach ($matches[1] as $potentialUid) {
                if ($this->isValidPageUid((int)$potentialUid)) {
                    $this->addLink($element, (string)$potentialUid, $links);
                }
            }
        }
    }

    private function isValidPageUid(int $uid): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $count = $queryBuilder
            ->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', 
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchOne();
            
        return $count > 0;
    }

    private function processMenuElement(array $content, array &$links): void    {
            // Check that the element is in an allowed colPos
    if (!in_array($content['colPos'], $this->allowedColPos)) {
        return;
    }
        switch ($content['CType']) {
            case 'menu_subpages':
            case 'menu_card_dir':
            case 'menu_card_list':
                if (!empty($content['pages'])) {
                    $parentPageUid = (int)$content['pages'];
                    $subPages = $this->getSubPages($parentPageUid);
                    foreach ($subPages as $subPage) {
                        $this->addLink($content, (string)$subPage['uid'], $links);
                    }
                }
                break;
                
            case 'menu_sitemap':
            case 'menu_sitemap_pages':
                // For a sitemap, we retrieve all pages from the root
                $rootLine = $this->getRootLine((int)$content['pid']);
                if (!empty($rootLine)) {
                    $rootPageUid = $rootLine[0]['uid'];
                    $allPages = $this->getAllPagesFromRoot($rootPageUid);
                    foreach ($allPages as $page) {
                        if ($page['uid'] !== $content['pid']) { // Avoid self-reference
                            $this->addLink($content, (string)$page['uid'], $links);
                        }
                    }
                }
                break;
                
            default:
                if (!empty($content['pages'])) {
                    $pages = GeneralUtility::intExplode(',', $content['pages'], true);
                    foreach ($pages as $pageUid) {
                        $this->addLink($content, (string)$pageUid, $links);
                    }
                }
        }
    }

    private function getRootLine(int $pageUid): array
    {
        $rootLine = [];
        $currentPage = $pageUid;

        while ($currentPage > 0) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(new DeletedRestriction());
                
            if (!$this->includeHidden) {
                $queryBuilder->getRestrictions()->add(new HiddenRestriction());
            }

            $page = $queryBuilder
                ->select('uid', 'pid', 'title', 'doktype')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq(
                        'uid',
                        $queryBuilder->createNamedParameter($currentPage, Connection::PARAM_INT)
                    )
                )
                ->executeQuery()
                ->fetchAssociative();

            if ($page) {
                $rootLine[] = $page;
                $currentPage = $page['pid'];
                
                // If we reach a root page (doktype=1), we stop
                if ($page['doktype'] === 1) {
                    break;
                }
            } else {
                break;
            }
        }

        return array_reverse($rootLine);
    }

    private function getAllPagesFromRoot(int $rootPageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());
            
        if (!$this->includeHidden) {
            $queryBuilder->getRestrictions()->add(new HiddenRestriction());
        }
    
        $rootPage = $queryBuilder
            ->select('uid', 'title', 'pid', 'doktype')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($rootPageUid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq('sys_language_uid', 0) // Filter on default language
            )
            ->executeQuery()
            ->fetchAssociative();
    
        if (!$rootPage) {
            return [];
        }
    
        $allPages = [$rootPage];
        $pagesToProcess = [$rootPageUid];
    
        while (!empty($pagesToProcess)) {
            $currentPageUids = $pagesToProcess;
            $pagesToProcess = [];
    
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(new DeletedRestriction());
                
            if (!$this->includeHidden) {
                $queryBuilder->getRestrictions()->add(new HiddenRestriction());
            }
    
            $subPages = $queryBuilder
                ->select('uid', 'title', 'pid', 'doktype')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in(
                        'pid',
                        $queryBuilder->createNamedParameter($currentPageUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                    ),
                    $queryBuilder->expr()->eq('sys_language_uid', 0) // Filter on default language
                )
                ->executeQuery()
                ->fetchAllAssociative();
    
            foreach ($subPages as $subPage) {
                if (!in_array($subPage['doktype'], $this->getExcludedDokTypes())) {
                    $allPages[] = $subPage;
                    $pagesToProcess[] = $subPage['uid'];
                }
            }
        }
    
        return $allPages;
    }


    private function addLink(array $element, string $targetPageId, array &$links): void
    {
        $links[] = [
            'sourcePageId' => (string)$element['pid'],
            'targetPageId' => $targetPageId,
            'contentElement' => [
                'uid' => $element['uid'],
                'type' => $element['CType'],
                'header' => $element['header'],
                'colPos' => $element['colPos']
            ]
        ];
    }

}