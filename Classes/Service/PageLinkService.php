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
    private bool $useLinkvalidator;
    private ?LinkvalidatorService $linkvalidatorService = null;

    public function __construct(?LinkvalidatorService $linkvalidatorService = null)
    {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->linkvalidatorService = $linkvalidatorService;

        // Retrieve the extension configuration
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('page_link_insights');

        // Convertir les colPos en tableau d'entiers
        $this->allowedColPos = GeneralUtility::intExplode(',', $this->extensionConfiguration['colPosToAnalyze'] ?? '0', true);
        $this->includeHidden = (bool)($this->extensionConfiguration['includeHidden'] ?? false);
        $this->includeShortcuts = (bool)($this->extensionConfiguration['includeShortcuts'] ?? false);
        $this->includeExternalLinks = (bool)($this->extensionConfiguration['includeExternalLinks'] ?? false);
        $this->includeSemanticSuggestions = (bool)($this->extensionConfiguration['includeSemanticSuggestions'] ?? true);
        $this->useLinkvalidator = (bool)($this->extensionConfiguration['useLinkvalidator'] ?? true);
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

    /**
     * Get broken links from linkvalidator if available and enabled
     *
     * @param array $pageUids List of page UIDs to check
     * @return array Broken links indexed by source page ID
     */
    private function getLinkvalidatorBrokenLinks(array $pageUids): array
    {
        if (!$this->useLinkvalidator || $this->linkvalidatorService === null) {
            return [];
        }

        if (!$this->linkvalidatorService->isAvailable()) {
            return [];
        }

        return $this->linkvalidatorService->getBrokenLinksForPages($pageUids);
    }

    /**
     * Check if linkvalidator integration is active
     */
    public function isLinkvalidatorActive(): bool
    {
        return $this->useLinkvalidator
            && $this->linkvalidatorService !== null
            && $this->linkvalidatorService->isAvailable();
    }

    /**
     * Get linkvalidator statistics if available
     */
    public function getLinkvalidatorStatistics(array $pageUids = []): array
    {
        if (!$this->isLinkvalidatorActive()) {
            return ['available' => false];
        }

        return $this->linkvalidatorService->getStatistics($pageUids);
    }

    public function getPageLinksForSubtree(int $pageUid): array
    {
        // Get all pages in the subtree (only pages under the selected page)
        $pages = $this->getPageTreeInfo($pageUid);

        if (empty($pages)) {
            return ['nodes' => [], 'links' => []];
        }

        // Get all content elements and their links from pages in the subtree
        $pageUids = array_column($pages, 'uid');
        $pageUidsString = array_map('strval', $pageUids);

        // Get direct content links (already filtered by colPos in getContentElementLinks)
        $allLinks = $this->getContentElementLinks($pageUids);

        // Filter links to only keep those where BOTH source AND target are in the subtree
        // This ensures we only see links between pages in the current view
        $allLinks = array_filter($allLinks, function($link) use ($pageUidsString) {
            return in_array($link['sourcePageId'], $pageUidsString, true)
                && in_array($link['targetPageId'], $pageUidsString, true);
        });

        // Re-index array after filtering
        $allLinks = array_values($allLinks);

        // Mark broken links using linkvalidator if available
        $linkvalidatorBrokenLinks = $this->getLinkvalidatorBrokenLinks($pageUids);

        $allLinks = array_map(function($link) use ($pageUidsString, $linkvalidatorBrokenLinks) {
            $sourceId = (int)$link['sourcePageId'];
            $targetId = (int)$link['targetPageId'];

            // Check linkvalidator data if available
            if (!empty($linkvalidatorBrokenLinks)) {
                if (isset($linkvalidatorBrokenLinks[$sourceId])) {
                    foreach ($linkvalidatorBrokenLinks[$sourceId] as $brokenLink) {
                        if ((int)$brokenLink['targetPageId'] === $targetId) {
                            $link['broken'] = true;
                            $link['brokenSource'] = 'linkvalidator';
                            return $link;
                        }
                    }
                }
            }

            // Links are already filtered to subtree, so they're not broken by default
            $link['broken'] = false;
            return $link;
        }, $allLinks);

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

        // Get semantic_suggestion settings (threshold and maxSuggestions)
        $semanticSettings = $this->getSemanticSuggestionSettings();
        $threshold = $semanticSettings['qualityLevel'];
        $maxSuggestionsPerPage = $semanticSettings['maxSuggestions'];

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_semanticsuggestion_similarities');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        // Only get suggestions where BOTH source AND target are in the subtree
        $similarities = $queryBuilder
            ->select('page_id', 'similar_page_id', 'similarity_score')
            ->from('tx_semanticsuggestion_similarities')
            ->where(
                $queryBuilder->expr()->in(
                    'page_id',
                    $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->in(
                    'similar_page_id',
                    $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->gte(
                    'similarity_score',
                    $queryBuilder->createNamedParameter($threshold, ParameterType::STRING)
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
            if ($suggestionsPerPage[$pageId] >= $maxSuggestionsPerPage) {
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
     * Get settings from semantic_suggestion TypoScript configuration
     */
    private function getSemanticSuggestionSettings(): array
    {
        // Default values matching semantic_suggestion defaults
        $defaults = [
            'qualityLevel' => 0.3,
            'maxSuggestions' => 3,
        ];

        try {
            $configurationManager = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Configuration\ConfigurationManager::class);
            $settings = $configurationManager->getConfiguration(
                \TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
                'SemanticSuggestion',
                'Suggestions'
            );

            if (!empty($settings)) {
                return [
                    'qualityLevel' => (float)($settings['qualityLevel'] ?? $settings['proximityThreshold'] ?? $defaults['qualityLevel']),
                    'maxSuggestions' => (int)($settings['maxSuggestions'] ?? $defaults['maxSuggestions']),
                ];
            }
        } catch (\Exception $e) {
            // Fall back to defaults if TypoScript is not available
        }

        return $defaults;
    }

    /**
     * Check if semantic suggestions should be included based on both extension availability and configuration
     */
    public function shouldIncludeSemanticSuggestions(): bool
    {
        return $this->includeSemanticSuggestions
            && (
                \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('semantic_suggestion')
                || \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('semantic_suggestion_solr')
            );
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

    /**
     * Fields that can contain page links in tt_content
     * Only these fields will be analyzed for links to avoid false positives
     */
    private const LINK_FIELDS = [
        'bodytext',
        'header_link',
        'pages',
        'records',
        'tx_gridelements_children',
        'pi_flexform',
        'image_link',
        'media',
    ];

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

        // Retrieve all fields but only analyze specific ones for links
        $contentElements = $queryBuilder
            ->select('*')
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
            // Only analyze fields that can contain links
            foreach (self::LINK_FIELDS as $fieldName) {
                if (isset($content[$fieldName]) && is_string($content[$fieldName]) && !empty($content[$fieldName])) {
                    $this->processTextLinks($content[$fieldName], $content, $links);
                }
            }

            // Special processing for menu content elements with explicit page references
            if (str_starts_with($content['CType'], 'menu_')) {
                $this->processMenuElement($content, $links);
            }
        }

        // Add semantic suggestion links
        if ($this->shouldIncludeSemanticSuggestions()) {
            $semanticLinks = $this->getSemanticSuggestionLinks($pageUids);
            $links = array_merge($links, $semanticLinks);
        }

        // Deduplicate links based on source, target and content element
        $links = $this->deduplicateLinks($links);

        return $links;
    }

    /**
     * Remove duplicate pages by UID
     */
    private function deduplicatePages(array $pages): array
    {
        $seen = [];
        $uniquePages = [];

        foreach ($pages as $page) {
            $uid = (string)$page['uid'];
            if (!isset($seen[$uid])) {
                $seen[$uid] = true;
                $uniquePages[] = $page;
            }
        }

        return $uniquePages;
    }

    /**
     * Remove duplicate links (same source, target and content element)
     */
    private function deduplicateLinks(array $links): array
    {
        $seen = [];
        $uniqueLinks = [];

        foreach ($links as $link) {
            $key = $link['sourcePageId'] . '-' . $link['targetPageId'] . '-' . ($link['contentElement']['uid'] ?? 0);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueLinks[] = $link;
            }
        }

        return $uniqueLinks;
    }

    private function processTextLinks(string $content, array $element, array &$links): void
    {
        // t3://page links (TYPO3 v8+)
        if (preg_match_all('/t3:\/\/page\?uid=(\d+)/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $this->addLink($element, (string)$pageUid, $links);
            }
        }

        // <link> tags (legacy RTE format)
        if (preg_match_all('/<link\s+(\d+)[^>]*>/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $this->addLink($element, (string)$pageUid, $links);
            }
        }

        // Legacy typolinks format: page,123 or t3://page,123
        if (preg_match_all('/\b(?:t3:\/\/)?page,(\d+)(?:,|\s|$)/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $this->addLink($element, (string)$pageUid, $links);
            }
        }

        // record:pages:UID links (used in some extensions)
        if (preg_match_all('/record:pages:(\d+)/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                $this->addLink($element, (string)$pageUid, $links);
            }
        }

        // TypoLink in href attributes: href="123" or href='123' (direct page UID)
        if (preg_match_all('/href=["\'](\d+)["\']/', $content, $matches)) {
            foreach ($matches[1] as $pageUid) {
                if ($this->isValidPageUid((int)$pageUid)) {
                    $this->addLink($element, (string)$pageUid, $links);
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

    private function processMenuElement(array $content, array &$links): void
    {
        // Check that the element is in an allowed colPos
        if (!in_array($content['colPos'], $this->allowedColPos)) {
            return;
        }

        switch ($content['CType']) {
            case 'menu_subpages':
            case 'menu_card_dir':
            case 'menu_card_list':
                // These menus reference a parent page and display its children
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
                // Sitemaps link to ALL pages in the site -- skip them
                // as they represent navigational structure, not editorial links
                break;

            case 'menu_pages':
            case 'menu_categorized_pages':
            case 'menu_categorized_content':
            case 'menu_recently_updated':
            case 'menu_related_pages':
            case 'menu_section':
            case 'menu_section_pages':
            default:
                // Explicit page list from the 'pages' field
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

    /**
     * Get all page UIDs in the subtree
     */
    public function getSubtreePageIds(int $pageUid): array
    {
        $pages = $this->getPageTreeInfo($pageUid);
        return array_column($pages, 'uid');
    }

}