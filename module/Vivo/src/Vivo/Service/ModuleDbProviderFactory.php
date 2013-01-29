<?php
namespace Vivo\Service;

use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\ServiceManager\FactoryInterface;

/**
 * ModuleDbProviderFactory
 */
class ModuleDbProviderFactory implements FactoryInterface
{
    /**
     * Create service
     * @param ServiceLocatorInterface $serviceLocator
     * @return mixed
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        $dbServiceManager   = $serviceLocator->get('db_service_manager');
        /** @var $siteEvent \Vivo\SiteManager\Event\SiteEventInterface */
        $siteEvent          = $serviceLocator->get('site_event');
        $siteConfig         = $siteEvent->getSiteConfig();
        $indexer        = new ModuleDbProvider($dbServiceManager, $siteConfig);
        return $indexer;
    }
}
