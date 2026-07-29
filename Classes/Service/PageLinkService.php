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
    private ReferenceIndexLinkProvider $referenceIndexLinkProvider;
    private array $extensionConfiguration;
    private array $allowedColPos;
    private bool $includeHidden;
    private bool $includeShortcuts;
    private bool $includeExternalLinks;
    private bool $includeSysFolders;
    private bool $includeSemanticSuggestions;

    public function __construct()
    {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->referenceIndexLinkProvider = GeneralUtility::makeInstance(ReferenceIndexLinkProvider::class);

        // Retrieve the extension configuration
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('page_link_insights');
            
        // Convertir les colPos en tableau d'entiers
        $this->allowedColPos = GeneralUtility::intExplode(',', $this->extensionConfiguration['colPosToAnalyze'] ?? '0', true);
        $this->includeHidden = (bool)($this->extensionConfiguration['includeHidden'] ?? false);
        $this->includeShortcuts = (bool)($this->extensionConfiguration['includeShortcuts'] ?? false);
        $this->includeExternalLinks = (bool)($this->extensionConfiguration['includeExternalLinks'] ?? false);
        $this->includeSysFolders = (bool)($this->extensionConfiguration['includeSysFolders'] ?? false);
        $this->includeSemanticSuggestions = (bool)($this->extensionConfiguration['includeSemanticSuggestions'] ?? true);
    }

    private function getExcludedDokTypes(): array
    {
        $excludedDokTypes = [
            255, // Recycler (legacy)
            199  // Menu separators - always exclude as they don't serve content
        ];

        // Conditionally exclude system folders, shortcuts and external links
        if (!$this->includeSysFolders) {
            $excludedDokTypes[] = 254; // System folders
        }

        if (!$this->includeShortcuts) {
            $excludedDokTypes[] = 4; // Shortcuts
        }

        if (!$this->includeExternalLinks) {
            $excludedDokTypes[] = 3; // External links
        }

        return $excludedDokTypes;
    }

    public function getPageLinksForSubtree(int $pageUid, int $languageId = 0): array
    {
        // Get all pages in the subtree (only pages under the selected page)
        $pages = $this->getPageTreeInfo($pageUid, $languageId);

        if (empty($pages)) {
            return ['nodes' => [], 'links' => []];
        }

        $pageUids = array_map('intval', array_column($pages, 'uid'));
        $subtree = array_flip($pageUids);

        $allLinks = $this->getContentElementLinks($pageUids, $languageId);

        // A target outside the subtree is either a page we deliberately left out
        // of this view, or a page that no longer exists. Only the latter is a
        // broken link, so resolve them in one query before classifying.
        $outsideTargets = [];
        foreach ($allLinks as $link) {
            $target = (int)$link['targetPageId'];
            if (!isset($subtree[$target])) {
                $outsideTargets[$target] = true;
            }
        }
        $missingTargets = $this->getMissingPageUids(array_keys($outsideTargets));

        $links = [];
        $danglingTargets = [];
        $seen = [];

        foreach ($allLinks as $link) {
            $source = (int)$link['sourcePageId'];
            $target = (int)$link['targetPageId'];

            if (!isset($subtree[$source])) {
                continue;
            }

            $isBroken = isset($missingTargets[$target]);
            if (!$isBroken && !isset($subtree[$target])) {
                // Existing page, but outside the analysed subtree: out of scope.
                continue;
            }

            // The same record may reference the same page several times (twice in
            // one RTE field, for instance). That is one relation, not several.
            $key = $source . '>' . $target
                . '#' . ($link['contentElement']['uid'] ?? 0)
                . '#' . ($link['sourceField'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $link['broken'] = $isBroken;
            $links[] = $link;

            if ($isBroken) {
                $danglingTargets[$target] = true;
            }
        }

        $nodes = array_map(fn($page) => [
            'id' => (string)$page['uid'],
            'title' => $page['title'],
        ], $pages);

        // Dangling targets have no page record to build a node from. Without a
        // node the diagram silently drops the very links that expose them.
        foreach (array_keys($danglingTargets) as $missingUid) {
            $nodes[] = [
                'id' => (string)$missingUid,
                'title' => '#' . $missingUid,
                'missing' => true,
            ];
        }

        return [
            'nodes' => array_values($nodes),
            'links' => $links,
        ];
    }

    /**
     * Of the given page uids, the ones that no longer resolve to a live page.
     *
     * @param int[] $pageUids
     * @return array<int, true> Keyed by uid for O(1) lookups
     */
    private function getMissingPageUids(array $pageUids): array
    {
        if ($pageUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        $existing = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchFirstColumn();

        $existing = array_flip(array_map('intval', $existing));

        $missing = [];
        foreach ($pageUids as $uid) {
            if (!isset($existing[$uid])) {
                $missing[$uid] = true;
            }
        }

        return $missing;
    }

    /**
     * Whether the reference index the diagram is built from holds any data.
     */
    public function isReferenceIndexPopulated(): bool
    {
        return $this->referenceIndexLinkProvider->isPopulated();
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

    /**
     * Every page of the subtree that should appear as a node.
     *
     * Traversal deliberately walks through pages the diagram will not display.
     * A shortcut, a link page or a system folder sitting mid-tree excludes only
     * itself; descending would otherwise amputate everything below it, which
     * silently hid whole branches of the site from the analysis.
     */
    private function getPageTreeInfo(int $rootPageId, int $languageId = 0): array
    {
        $excludedDokTypes = array_flip($this->getExcludedDokTypes());

        $rootPage = $this->fetchPages(['uid' => $rootPageId]);
        if ($rootPage === []) {
            return [];
        }

        $allPages = $rootPage;
        $pagesToProcess = [$rootPageId];
        $visited = [$rootPageId => true];

        // Traverse the tree recursively
        while ($pagesToProcess !== []) {
            $childPages = $this->fetchPages(['pid' => $pagesToProcess]);

            $pagesToProcess = [];
            foreach ($childPages as $page) {
                $uid = (int)$page['uid'];
                // Guard against a corrupted pid chain looping back on itself.
                if (isset($visited[$uid])) {
                    continue;
                }
                $visited[$uid] = true;

                $allPages[] = $page;
                $pagesToProcess[] = $uid;
            }
        }

        $allPages = array_values(array_filter(
            $allPages,
            fn(array $page) => !isset($excludedDokTypes[(int)$page['doktype']])
        ));

        return $this->applyTranslatedTitles($allPages, $languageId);
    }

    /**
     * Replace node labels with their translation.
     *
     * The tree itself is not translated: a page translation carries no children
     * of its own, it hangs off the original. So the graph keeps the default
     * language structure and only the labels follow the selected language.
     * Pages left untranslated keep their original title, which makes gaps in
     * translation coverage visible rather than hiding those pages.
     */
    private function applyTranslatedTitles(array $pages, int $languageId): array
    {
        if ($languageId <= 0 || $pages === []) {
            return $pages;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        if (!$this->includeHidden) {
            $queryBuilder->getRestrictions()->add(new HiddenRestriction());
        }

        $rows = $queryBuilder
            ->select('title', 'l10n_parent')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'l10n_parent',
                    $queryBuilder->createNamedParameter(
                        array_map('intval', array_column($pages, 'uid')),
                        Connection::PARAM_INT_ARRAY
                    )
                ),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $translatedTitle = [];
        foreach ($rows as $row) {
            $translatedTitle[(int)$row['l10n_parent']] = (string)$row['title'];
        }

        foreach ($pages as &$page) {
            $uid = (int)$page['uid'];
            if (isset($translatedTitle[$uid]) && $translatedTitle[$uid] !== '') {
                $page['title'] = $translatedTitle[$uid];
            }
        }

        return $pages;
    }

    /**
     * Fetch live, default-language pages by uid or by parent.
     *
     * @param array{uid?: int, pid?: int[]} $constraint
     */
    private function fetchPages(array $constraint): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        if (!$this->includeHidden) {
            $queryBuilder->getRestrictions()->add(new HiddenRestriction());
        }

        $queryBuilder
            ->select('uid', 'pid', 'title', 'doktype')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            );

        if (isset($constraint['uid'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($constraint['uid'], Connection::PARAM_INT)
                )
            );
        }

        if (isset($constraint['pid'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($constraint['pid'], Connection::PARAM_INT_ARRAY)
                )
            );
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
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

    private function getContentElementLinks(array $pageUids, int $languageId = 0): array
    {
        if (empty($pageUids)) {
            return [];
        }

        $links = [];

        // Every explicit reference to a page, whichever table and field it was
        // authored in, as recorded by the TYPO3 reference index.
        $references = $this->referenceIndexLinkProvider->getReferences(
            $pageUids,
            $this->allowedColPos,
            $this->includeHidden,
            $languageId
        );

        foreach ($references as $reference) {
            $links[] = [
                'sourcePageId' => (string)$reference['sourcePageUid'],
                'targetPageId' => (string)$reference['targetPageUid'],
                'contentElement' => $reference['contentElement'],
                'sourceField' => $reference['sourceField'],
            ];
        }

        // Menus resolve to pages the reference index cannot know about: a menu
        // pointing at one page renders links to that page's children.
        $links = array_merge($links, $this->getMenuLinks($pageUids, $languageId));

        // Add semantic suggestion links
        if ($this->shouldIncludeSemanticSuggestions()) {
            $links = array_merge($links, $this->getSemanticSuggestionLinks($pageUids));
        }

        return $links;
    }

    /**
     * Links generated by menu content elements, expanded to the pages the menu
     * actually renders rather than the page it points at.
     *
     * @param int[] $pageUids
     */
    private function getMenuLinks(array $pageUids, int $languageId = 0): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        if (!$this->includeHidden) {
            $queryBuilder->getRestrictions()->add(new HiddenRestriction());
        }

        $menuElements = $queryBuilder
            ->select('uid', 'pid', 'CType', 'header', 'colPos', 'pages')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->in(
                    'colPos',
                    $queryBuilder->createNamedParameter($this->allowedColPos, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->in(
                    'CType',
                    $queryBuilder->createNamedParameter(
                        ReferenceIndexLinkProvider::EXPANDED_MENU_CTYPES,
                        Connection::PARAM_STR_ARRAY
                    )
                ),
                $queryBuilder->expr()->in(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter([$languageId, -1], Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $links = [];
        foreach ($menuElements as $content) {
            $this->processMenuElement($content, $links);
        }

        return $links;
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