<?php

namespace Cwolf\PageLinkInsights\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use Cwolf\PageLinkInsights\Service\PageLinkService;

class BackendController extends ActionController
{
    protected array $extensionSettings;
    protected bool $debugMode = true;
    
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly PageRepository $pageRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly PageLinkService $pageLinkService
    ) {
    }

    protected function initializeAction(): void
    {
        parent::initializeAction();
        $this->extensionSettings = $this->extensionConfiguration->get('page_link_insights') ?? [];
        $this->debug('Controller initialized');
        
        // Ajouter les fichiers JS nécessaires dès l'initialisation
        $this->pageRenderer->addJsFile(
            'EXT:page_link_insights/Resources/Public/JavaScript/Vendor/d3.min.js'
        );
        
        $this->pageRenderer->addJsFile(
            'EXT:page_link_insights/Resources/Public/JavaScript/force-diagram.js'
        );
    }

    public function mainAction(): ResponseInterface
    {
        $pageUid = (int)($this->request->getQueryParams()['id'] ?? 0);
        error_log('BackendController - Page UID: ' . $pageUid);
    
        if ($pageUid === 0) {
            $data = ['nodes' => [], 'links' => []];
        } else {
            try {
                error_log('BackendController - Calling PageLinkService');
                $data = $this->pageLinkService->getPageLinksForSubtree($pageUid);
            } catch (\Exception $e) {
                error_log('BackendController - Error: ' . $e->getMessage());
                $data = ['nodes' => [], 'links' => []];
            }
        }
    
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assignMultiple([
            'data' => json_encode($data),
            'noPageSelected' => ($pageUid === 0),
        ]);
    
        return $moduleTemplate->renderResponse('Main');
    }
    

    protected function initialize(): void
    {
        parent::initialize();
        $this->debug('Controller initialized');
    }

    protected function debug(string $message, mixed $data = null): void
    {
        if (!$this->debugMode) {
            return;
        }

        $debugInfo = [
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => $message,
            'data' => $data
        ];

        if (!isset($GLOBALS['PAGE_LINK_INSIGHTS_DEBUG'])) {
            $GLOBALS['PAGE_LINK_INSIGHTS_DEBUG'] = [];
        }
        
        $GLOBALS['PAGE_LINK_INSIGHTS_DEBUG'][] = $debugInfo;
    }

    protected function getDebugLog(): array
    {
        return $GLOBALS['PAGE_LINK_INSIGHTS_DEBUG'] ?? [];
    }


}