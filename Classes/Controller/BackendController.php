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
        $this->debug('Starting mainAction');
        
        // Récupérer la page sélectionnée dans le page tree
        $pageUid = (int)($this->request->getQueryParams()['id'] ?? 0);
        $this->debug('Page UID from request', $pageUid);
            
        if ($pageUid === 0) {
            $this->debug('No page selected, showing default view');
            $this->view->assign('noPageSelected', true);
            $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
            $moduleTemplate->setContent($this->view->render());
            return $this->htmlResponse($moduleTemplate->renderContent());
        }
    
        try {
            // Utiliser PageLinkService pour obtenir les données
            $data = $this->pageLinkService->getPageLinksForSubtree($pageUid);
            $this->debug('Graph data received from PageLinkService', $data);
    
        } catch (\Exception $e) {
            $this->debug('Error occurred', $e->getMessage());
            $data = ['nodes' => [], 'links' => []];
        }
    
        // Préparer la vue
        $this->view->assign('data', json_encode($data));
        $this->view->assign('noPageSelected', false);
    
        // Ajouter le debug log à la vue si le mode debug est actif
        if ($this->debugMode) {
            $this->view->assign('debugLog', json_encode($this->getDebugLog(), JSON_PRETTY_PRINT));
        }
    
        // Rendu final
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setContent($this->view->render());
        
        return $this->htmlResponse($moduleTemplate->renderContent());
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