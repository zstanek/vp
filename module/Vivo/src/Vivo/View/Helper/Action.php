<?php
namespace Vivo\View\Helper;

use Vivo\UI\Component;

use Zend\Mvc\Router\RouteStackInterface;
use Zend\View\Helper\AbstractHelper;
use Zend\View\Helper\Url;

/**
 * View helper for gettting action url
 */
class Action extends AbstractHelper
{
    public function __invoke($action, $params = array())
    {
        $model = $this->view->getCurrentModel();
        $component = $model->getVariable('component');
        return $component['path'] . Component::COMPONENT_SEPARATOR . $action;
    }
}
