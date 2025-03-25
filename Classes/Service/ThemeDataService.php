<?php

namespace Cywolf\PageLinkInsights\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Psr\Log\LoggerInterface;
use Cywolf\NlpTools\Service\TextAnalysisService;
use Cywolf\NlpTools\Service\LanguageDetectionService;

class ThemeDataService {
    private ConnectionPool $connectionPool;
    private LoggerInterface $logger;
    private CacheManager $cacheManager;
    private TextAnalysisService $textAnalyzer;
    private LanguageDetectionService $languageDetector;
    
    public function __construct() {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->logger = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)->getLogger(__CLASS__);
        $this->cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $this->textAnalyzer = GeneralUtility::makeInstance(TextAnalysisService::class);
        $this->languageDetector = GeneralUtility::makeInstance(LanguageDetectionService::class);
    }

/**
 * Extrait les termes significatifs d'un contenu textuel
 * 
 * @param string $content Le contenu à analyser
 * @param int $pageId L'ID de la page
 * @return array Un tableau des termes significatifs avec leurs fréquences
 */
private function extractSignificantTerms(string $content, int $pageId): array
{
    try {
        // Détecter la langue du contenu
        $language = $this->languageDetector->detectLanguage($content);
        

        // Ajouter au début
        $logFile = \TYPO3\CMS\Core\Core\Environment::getProjectPath() . '/var/log/theme_extraction_test.log';
        file_put_contents($logFile, "=== Test d'extraction pour la page $pageId ===\n", FILE_APPEND);

        // Quelques logs conservés pour le diagnostic
        if ($this->debugMode) {
            $this->logger->info('Extraction de termes pour la page ' . $pageId, [
                'langue' => $language,
                'longueur_contenu' => strlen($content)
            ]);
        }
        
        // Supprimer complètement les balises HTML
        $cleanedContent = strip_tags($content);
        
        // Supprimer les entités HTML (comme &nbsp;)
        $cleanedContent = html_entity_decode($cleanedContent, ENT_QUOTES, 'UTF-8');
        
        // Nettoyer les caractères spéciaux
        $cleanedContent = preg_replace('/[^\p{L}\s]/u', ' ', $cleanedContent);
        $cleanedContent = preg_replace('/\s+/', ' ', $cleanedContent);
        
        // Ajouter une liste personnalisée de "stopwords" liés au HTML/CSS/JS
        $htmlStopwords = ['class', 'href', 'div', 'span', 'http', 'https', 'www', 'img', 'src'];
        
        try {
            // Tokenizer et enlever les stop words
            file_put_contents($logFile, "Tentative d'utilisation de nlp_tools...\n", FILE_APPEND);
            $processedContent = $this->textAnalyzer->removeStopWords($cleanedContent, $language);
            $tokens = $this->textAnalyzer->tokenize($processedContent);
            file_put_contents($logFile, "nlp_tools fonctionne correctement! " . count($tokens) . " tokens extraits\n", FILE_APPEND);
            
            
            // Vérifier si $tokens est un tableau valide
            if (!is_array($tokens)) {
                if ($this->debugMode) {
                    $this->logger->warning('Erreur de tokenization, utilisation de la méthode de secours', [
                        'pageId' => $pageId
                    ]);
                }
                return $this->fallbackExtractKeywords($cleanedContent, $pageId);
            }
            
            // Stocker les versions originales des tokens avant stemming
            $originalTokens = [];
            foreach ($tokens as $token) {
                $tokenLower = strtolower($token);
                if (strlen($tokenLower) > 3 && strlen($tokenLower) < 30 && !is_numeric($tokenLower) 
                    && !preg_match('/[0-9]/', $tokenLower) && !in_array($tokenLower, $htmlStopwords)) {
                    $originalTokens[] = $token;
                }
            }
            
            // Créer un mapping entre les stems et les tokens originaux
            $stemToOriginalMap = [];
            $stems = $this->textAnalyzer->stem($processedContent, $language);
            file_put_contents($logFile, "Stemming réussi! " . count($stems) . " stems extraits\n", FILE_APPEND);

            if (!is_array($stems)) {
                if ($this->debugMode) {
                    $this->logger->warning('Erreur de stemming, utilisation de la méthode de secours', [
                        'pageId' => $pageId
                    ]);
                }
                return $this->fallbackExtractKeywords($cleanedContent, $pageId);
            }
            
            // Vérifier que les deux tableaux ont la même longueur
            $minLength = min(count($tokens), count($stems));
            for ($i = 0; $i < $minLength; $i++) {
                $stem = $stems[$i];
                $originalToken = $tokens[$i];
                
                // Seulement si le token original est dans notre liste filtrée
                if (in_array($originalToken, $originalTokens)) {
                    if (!isset($stemToOriginalMap[$stem])) {
                        $stemToOriginalMap[$stem] = [];
                    }
                    $stemToOriginalMap[$stem][] = $originalToken;
                }
            }
            
            // Utiliser les stems pour le comptage de fréquence
            $stemmedTokens = array_filter($stems, function($token) use ($htmlStopwords) {
                return strlen($token) > 3 
                    && strlen($token) < 30 
                    && !is_numeric($token)
                    && !preg_match('/[0-9]/', $token)
                    && !in_array(strtolower($token), $htmlStopwords);
            });
            
            // Compter les occurrences des stems
            $stemFrequency = array_count_values($stemmedTokens);
            
            // Ne garder que les termes apparaissant plus d'une fois
            $stemFrequency = array_filter($stemFrequency, fn($freq) => $freq > 1);
            
            // Trier par fréquence
            arsort($stemFrequency);
            
            // Préparer le résultat final avec les formes originales
            $termFrequency = [];
            foreach (array_slice($stemFrequency, 0, 15, true) as $stem => $frequency) {
                // Choisir la forme originale la plus fréquente (pour le moment, on prend juste la première)
                $originalForm = isset($stemToOriginalMap[$stem]) && !empty($stemToOriginalMap[$stem]) 
                    ? $stemToOriginalMap[$stem][0] 
                    : $stem;
                
                // Mettre en majuscule la première lettre pour une meilleure présentation
                $originalForm = ucfirst($originalForm);
                
                $termFrequency[$originalForm] = $frequency;
            }
            
            if (count($termFrequency) === 0 && $this->debugMode) {
                $this->logger->info('Aucun terme trouvé, utilisation de la méthode de secours', [
                    'pageId' => $pageId
                ]);
                return $this->fallbackExtractKeywords($cleanedContent, $pageId);
            }
            
            return $termFrequency;
            
        } catch (\Exception $nlpError) {
            if ($this->debugMode) {
                $this->logger->warning('Exception NLP: ' . $nlpError->getMessage(), [
                    'pageId' => $pageId
                ]);
            }
            file_put_contents($logFile, "ERREUR AVEC NLP-TOOLS: " . $nlpError->getMessage() . "\n", FILE_APPEND);
            return $this->fallbackExtractKeywords($cleanedContent, $pageId);
        }
        
    } catch (\Exception $e) {
        $this->logger->error('Erreur lors de l\'analyse du texte', [
            'pageId' => $pageId,
            'error' => $e->getMessage()
        ]);
        
        return $this->fallbackExtractKeywords($cleanedContent, $pageId);
    }
}

/**
 * Méthode de secours pour extraire des mots-clés sans utiliser NLP Tools
 * Utilisée lorsque l'analyse NLP échoue ou ne produit pas de résultats
 * 
 * @param string $content Le contenu à analyser
 * @param int $pageId L'ID de la page
 * @return array Un tableau des termes avec leurs fréquences
 */
private function fallbackExtractKeywords(string $content, int $pageId): array
{
    if ($this->debugMode) {
        $this->logger->info('Utilisation de la méthode de secours pour l\'extraction de mots-clés', [
            'pageId' => $pageId
        ]);
    }
    
    try {
        // Nettoyer le contenu s'il n'est pas déjà nettoyé
        if (strpos($content, '<') !== false) {
            $content = strip_tags($content);
            $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
            $content = preg_replace('/[^\p{L}\s]/u', ' ', $content);
            $content = preg_replace('/\s+/', ' ', $content);
        }
        
        // Liste de stop words basique (anglais + allemand + français)
        $stopWords = ['a', 'an', 'the', 'and', 'or', 'but', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
                      'in', 'on', 'at', 'to', 'for', 'with', 'by', 'about', 'against', 'between', 'into', 'through',
                      'der', 'die', 'das', 'und', 'oder', 'aber', 'ist', 'sind', 'war', 'waren', 'sein', 'gewesen',
                      'in', 'auf', 'an', 'zu', 'für', 'mit', 'durch', 'über', 'unter', 'gegen', 'zwischen', 'hinein',
                      'le', 'la', 'les', 'un', 'une', 'et', 'ou', 'mais', 'est', 'sont', 'était', 'être', 'ont',
                      'dans', 'sur', 'pour', 'avec', 'par', 'que', 'qui', 'donc', 'alors', 'si', 'quand',
                      'ces', 'tous', 'toutes', 'leur', 'leurs', 'votre', 'vos', 'notre', 'nos', 'mon', 'ma', 'mes'];
        
        // Tokenizer le texte
        $words = preg_split('/\s+/', strtolower($content));
        
        // Filtrer les mots courts et les stop words
        $filteredWords = array_filter($words, function($word) use ($stopWords) {
            return strlen($word) > 3 && !in_array($word, $stopWords) && !is_numeric($word) && !preg_match('/[0-9]/', $word);
        });
        
        // Compter les occurrences
        $wordCount = array_count_values($filteredWords);
        
        // Ne garder que les termes apparaissant plus d'une fois
        $wordCount = array_filter($wordCount, fn($freq) => $freq > 1);
        
        // Trier par fréquence
        arsort($wordCount);
        
        // Prendre les 15 premiers mots avec mise en majuscule
        $result = [];
        foreach (array_slice($wordCount, 0, 15, true) as $word => $freq) {
            $result[ucfirst($word)] = $freq;
        }
        
        if (count($result) === 0) {
            if ($this->debugMode) {
                $this->logger->notice('Aucun mot-clé trouvé, utilisation de mots-clés génériques', [
                    'pageId' => $pageId
                ]);
            }
            
            // Si aucun mot-clé n'est trouvé, créer des mots-clés génériques
            return [
                'Content' => 10,
                'Page' => 9,
                'Information' => 8,
                'Website' => 7,
                'Menu' => 6
            ];
        }
        
        return $result;
        
    } catch (\Exception $e) {
        $this->logger->error('Erreur dans fallbackExtractKeywords', [
            'message' => $e->getMessage(),
            'pageId' => $pageId
        ]);
        
        // Retourner au moins quelques mots-clés génériques pour que ça fonctionne
        return [
            'Content' => 10,
            'Page' => 9,
            'Information' => 8,
            'Website' => 7,
            'Menu' => 6
        ];
    }
}
    
    public function getThemesForSubtree(int $pageUid): array
    {
        try {
            $cacheIdentifier = 'themes_' . $pageUid;
            $cache = $this->cacheManager->getCache('pages');
            
            // Essayer de récupérer du cache
            $themeData = $cache->get($cacheIdentifier);
            if ($themeData) {
                return $themeData;
            }
            
            // Récupérer toutes les pages du sous-arbre
            $pageIds = $this->getSubtreePageIds($pageUid);
            
            // Récupérer les données
            $themeData = [
                'themes' => $this->getThemes($pageIds),
                'pageThemes' => $this->getPageThemeAssociations($pageIds),
                'keywords' => $this->getTopKeywords($pageIds)
            ];
            
            // Stocker en cache
            $cache->set($cacheIdentifier, $themeData);
            
            return $themeData;
            
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la récupération des thèmes', [
                'pageUid' => $pageUid,
                'error' => $e->getMessage()
            ]);
            return [
                'themes' => [],
                'pageThemes' => [],
                'keywords' => []
            ];
        }
    }

    public function analyzePageContent(int $pageUid): void {
        try {
            // Récupérer toutes les pages du sous-arbre
            $pageIds = $this->getSubtreePageIds($pageUid);
            
            // Récupérer le contenu des pages
            $pageContents = $this->getPageContents($pageIds);
            
            // 1. Analyse des mots-clés par page
            $pageKeywords = [];
            foreach ($pageContents as $pid => $content) {
                // Correction : On passe maintenant le $pid comme second paramètre
                $keywords = $this->extractSignificantTerms($content, $pid);
                $this->saveKeywords($pid, $keywords);
                $pageKeywords[$pid] = $keywords;
            }
            
            // 2. Identifier les thèmes globaux
            $globalThemes = $this->identifyThemes($pageKeywords);
            
            // 3. Sauvegarder les thèmes
            $themeIds = $this->saveThemes($globalThemes);
            
            // 4. Créer les associations pages-thèmes
            $this->createPageThemeAssociations($pageKeywords, $themeIds);
            
            $this->logger->info('Analyse thématique terminée', [
                'pageUid' => $pageUid,
                'contentCount' => count($pageContents),
                'themesCount' => count($globalThemes)
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de l\'analyse du contenu', [
                'pageUid' => $pageUid,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function identifyThemes(array $pageKeywords): array {
        // Regrouper tous les mots-clés
        $allKeywords = [];
        foreach ($pageKeywords as $keywords) {
            foreach ($keywords as $word => $freq) {
                if (!isset($allKeywords[$word])) {
                    $allKeywords[$word] = 0;
                }
                $allKeywords[$word] += $freq;
            }
        }
        
        // Trier par fréquence
        arsort($allKeywords);
        
        // Prendre les N mots les plus fréquents comme thèmes
        $themes = array_slice($allKeywords, 0, 10, true);
        
        return array_map(function($keyword, $frequency) {
            return [
                'name' => $keyword,
                'weight' => $frequency,
                'keywords' => [$keyword] // Pour le moment, chaque thème est basé sur un mot-clé
            ];
        }, array_keys($themes), array_values($themes));
    }

    private function saveThemes(array $themes): array {
        $connection = $this->connectionPool->getConnectionForTable('tx_pagelinkinsights_themes');
        $now = time();
        $themeIds = [];
        
        foreach ($themes as $theme) {
            $connection->insert(
                'tx_pagelinkinsights_themes',
                [
                    'pid' => 0,
                    'tstamp' => $now,
                    'crdate' => $now,
                    'theme_name' => $theme['name'],
                    'keywords' => json_encode($theme['keywords']),
                    'weight' => $theme['weight'],
                    'language' => 0
                ]
            );
            
            $themeIds[$theme['name']] = $connection->lastInsertId('tx_pagelinkinsights_themes');
        }
        
        return $themeIds;
    }

    private function createPageThemeAssociations(array $pageKeywords, array $themeIds): void {
        $connection = $this->connectionPool->getConnectionForTable('tx_pagelinkinsights_page_themes');
        $now = time();
        
        foreach ($pageKeywords as $pageId => $keywords) {
            // Pour chaque thème, calculer la pertinence pour cette page
            foreach ($themeIds as $themeName => $themeId) {
                // Chercher une correspondance exacte ou le mot-clé est contenu dans le nom du thème
                $relevance = 0;
                foreach ($keywords as $keyword => $frequency) {
                    // Correspondance exacte
                    if ($keyword === $themeName) {
                        $relevance = $frequency;
                        break;
                    }
                    // Le mot-clé fait partie du nom du thème
                    elseif (stripos($themeName, $keyword) !== false || stripos($keyword, $themeName) !== false) {
                        $relevance = max($relevance, $frequency * 0.8); // pondérer un peu moins pour les correspondances partielles
                    }
                }
                
                if ($relevance > 0) {
                    $connection->insert(
                        'tx_pagelinkinsights_page_themes',
                        [
                            'pid' => 0,
                            'tstamp' => $now,
                            'crdate' => $now,
                            'page_uid' => $pageId,
                            'theme_uid' => $themeId,
                            'relevance' => $relevance
                        ]
                    );
                }
            }
        }
    }
    
    private function getSubtreePageIds(int $rootPageId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        
        // D'abord récupérer la page racine
        $rootPage = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', 
                    $queryBuilder->createNamedParameter($rootPageId)
                )
            )
            ->executeQuery()
            ->fetchAssociative();
            
        if (!$rootPage) {
            return [];
        }
        
        $allPageIds = [$rootPage['uid']];
        $pagesToProcess = [$rootPage['uid']];
        
        // Parcourir récursivement l'arborescence
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
                        $queryBuilder->createNamedParameter($currentPageIds, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
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
    
    private function getThemes(array $pageIds): array {
        if (empty($pageIds)) {
            return [];
        }
        
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_themes');
        
        return $queryBuilder
            ->select('*')
            ->from('tx_pagelinkinsights_themes')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($pageIds, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->orderBy('weight', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }
    
    private function getPageThemeAssociations(array $pageIds): array {
        if (empty($pageIds)) {
            return [];
        }
        
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_page_themes');
        
        $associations = $queryBuilder
            ->select('pt.*', 't.theme_name', 't.keywords')
            ->from('tx_pagelinkinsights_page_themes', 'pt')
            ->join(
                'pt',
                'tx_pagelinkinsights_themes',
                't',
                'pt.theme_uid = t.uid'
            )
            ->where(
                $queryBuilder->expr()->in(
                    'pt.page_uid',
                    $queryBuilder->createNamedParameter($pageIds, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->orderBy('pt.relevance', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
            
        // Organiser par page
        $byPage = [];
        foreach ($associations as $assoc) {
            $pageUid = $assoc['page_uid'];
            if (!isset($byPage[$pageUid])) {
                $byPage[$pageUid] = [];
            }
            $byPage[$pageUid][] = [
                'theme' => $assoc['theme_name'],
                'relevance' => $assoc['relevance'],
                'keywords' => json_decode($assoc['keywords'], true)
            ];
        }
        
        return $byPage;
    }
    
    private function getPageContents(array $pageIds): array {
        if (empty($pageIds)) {
            return [];
        }
        
        // Récupérer la configuration colPos depuis l'extension
        $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $config = $extensionConfiguration->get('page_link_insights');
        $allowedColPos = GeneralUtility::intExplode(',', $config['colPosToAnalyze'] ?? '0', true);
        
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        
        $contents = $queryBuilder
            ->select('pid', 'header', 'bodytext', 'subheader')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($pageIds, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->in(
                    'colPos',
                    $queryBuilder->createNamedParameter($allowedColPos, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
            
        // Regrouper par page
        $pageContents = [];
        foreach ($contents as $content) {
            if (!isset($pageContents[$content['pid']])) {
                $pageContents[$content['pid']] = '';
            }
            $pageContents[$content['pid']] .= ' ' . $content['header'] . ' ' . 
                                            $content['subheader'] . ' ' . 
                                            $content['bodytext'];
        }
        
        return $pageContents;
    }
    
    
    private function saveKeywords(int $pageUid, array $keywords): void {
        $connection = $this->connectionPool->getConnectionForTable('tx_pagelinkinsights_keywords');
        $now = time();
        
        foreach ($keywords as $keyword => $frequency) {
            $connection->insert(
                'tx_pagelinkinsights_keywords',
                [
                    'pid' => 0,
                    'tstamp' => $now,
                    'crdate' => $now,
                    'page_uid' => $pageUid,
                    'keyword' => $keyword,
                    'frequency' => $frequency,
                    'weight' => 1.0,
                    'language' => 0
                ]
            );
        }
    }
    
    private function getTopKeywords(array $pageIds): array {
        if (empty($pageIds)) {
            return [];
        }
        
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_pagelinkinsights_keywords');
        
        return $queryBuilder
            ->select('keyword')
            ->addSelectLiteral('SUM(frequency * weight) as total_weight')
            ->from('tx_pagelinkinsights_keywords')
            ->where(
                $queryBuilder->expr()->in(
                    'page_uid',
                    $queryBuilder->createNamedParameter($pageIds, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->groupBy('keyword')
            ->orderBy('total_weight', 'DESC')
            ->setMaxResults(50)
            ->executeQuery()
            ->fetchAllAssociative();
    }
    
    public function enrichNodesWithThemes(array $nodes, array $themeData): array {
        foreach ($nodes as &$node) {
            $pageUid = $node['id'];
            
            if (isset($themeData['pageThemes'][$pageUid])) {
                // Ajouter les thèmes principaux de la page
                $node['themes'] = $themeData['pageThemes'][$pageUid];
                
                // Ajouter le thème dominant (celui avec la plus haute pertinence)
                $dominantTheme = reset($themeData['pageThemes'][$pageUid]);
                $node['mainTheme'] = [
                    'name' => $dominantTheme['theme'],
                    'relevance' => $dominantTheme['relevance']
                ];
            }
        }
        
        return $nodes;
    }

    /**
     * Mode de débogage pour activer les logs détaillés
     */
    protected bool $debugMode = false;

}
