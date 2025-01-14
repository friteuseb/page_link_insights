<?php

namespace Cwolf\PageLinkInsights\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class BackendController extends ActionController
{
    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly PageRenderer $pageRenderer
    ) {
    }

    public function mainAction(): ResponseInterface
    {
        // Add D3.js library - version simplifiée
        $this->pageRenderer->addJsFile(
            'EXT:page_link_insights/Resources/Public/JavaScript/Vendor/d3.min.js',
        );
    
        // Add custom JS - version simplifiée, mais après D3.js
        $this->pageRenderer->addJsFile(
            'EXT:page_link_insights/Resources/Public/JavaScript/force-diagram.js',
        );
    
        $data = [
            'nodes' => [
                ['id' => 'A'],
                ['id' => 'B'],
                ['id' => 'C'],
            ],
            'links' => [
                ['source' => 'A', 'target' => 'B'],
                ['source' => 'B', 'target' => 'C'],
            ]
        ];
    
        $this->view->assign('data', json_encode($data));
    
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setContent($this->view->render());
        
        return $this->htmlResponse($moduleTemplate->renderContent());
    }
}