<?php
namespace Vivo\Repository;

use Vivo;
use Vivo\CMS;
use Vivo\CMS\Model;
use Vivo\Storage;
use Vivo\Indexer\Indexer;
use Vivo\Repository\UuidConvertor\UuidConvertorInterface;
use Vivo\Repository\Watcher;
use Vivo\Storage\PathBuilder\PathBuilderInterface;

use Vivo\IO;
use Vivo\Storage\StorageInterface;
use Vivo\Uuid\GeneratorInterface as UuidGenerator;
use Vivo\IO\IOUtil;
use Vivo\Repository\Exception;
use Vivo\Repository\IndexerHelper;
use Vivo\Indexer\Term as IndexerTerm;

use Zend\Serializer\Adapter\AdapterInterface as Serializer;
use Zend\Cache\Storage\StorageInterface as Cache;

/**
 * Repository class provides methods to work with CMS repository.
 * Repository supports transactions.
 * Commit method commits the current transaction, making its changes permanent.
 * Rollback rolls back the current transaction, canceling its changes.
 */
class Repository implements RepositoryInterface
{

	const ENTITY_FILENAME = 'Entity.object';

	const UUID_PATTERN = '[\d\w]{32}';

//	const URL_PATTERN = '\/[\w\d\-\/\.]+\/';

	/**
	 * @var \Vivo\Storage\StorageInterface
	 */
	private $storage;

	/**
	 * @var \Vivo\Indexer\Indexer
	 */
	private $indexer;

    /**
     * Indexer helper
     * @var IndexerHelper
     */
    protected $indexerHelper;

    /**
     * UUID Convertor
     * @var UuidConvertorInterface
     */
    protected $uuidConvertor;

    /**
     * Object watcher
     * @var Watcher
     */
    protected $watcher;

	/**
	 * @var Serializer
	 */
	private $serializer;

    /**
     * The cache for objects of the model
     * The entities are passed to this cache as objects, thus the cache has to support serialization
     * @var Cache
     */
    protected $cache;

    /**
     * PathBuilder
     * @var PathBuilderInterface
     */
    protected $pathBuilder;

    /**
     * @var UuidGenerator
     */
    protected $uuidGenerator;

    /**
     * @var IOUtil
     */
    protected $ioUtil;

    /**
     * List of entities that are prepared to be persisted
     * @var Model\Entity[]
     */
    protected $saveEntities         = array();

    /**
     * List of streams that are prepared to be persisted
     * Map: path => stream
     * @var IO\InputStreamInterface[]
     */
    protected $saveStreams          = array();

    /**
     * List of data items prepared to be persisted
     * Map: path => data
     * @var string[]
     */
    protected $saveData             = array();

    /**
     * List of files that are prepared to be copied
     * @var array
     */
    protected $copyFiles            = array();

    /**
     * List of entities prepared for deletion
     * @var Model\Entity[]
     */
    protected $deleteEntities       = array();

    /**
     * List of paths that are prepared to be deleted
     * @var string[]
     */
    protected $deletePaths          = array();

    /**
     * List of temporary files which will be moved to their final place if everything is well in commit
     * Map: path => tempPath
     * @var string[]
     */
    protected $tmpFiles             = array();

    /**
     * List of temporary paths which will be deleted if everything is well in commit
     * Map: path => tempPath
     * @var string[]
     */
    protected $tmpDelFiles          = array();

    /**
     * Constructor
     * @param \Vivo\Storage\StorageInterface $storage
     * @param \Zend\Cache\Storage\StorageInterface $cache
     * @param \Vivo\Indexer\Indexer $indexer
     * @param IndexerHelper $indexerHelper
     * @param \Zend\Serializer\Adapter\AdapterInterface $serializer
     * @param UuidConvertor\UuidConvertorInterface $uuidConvertor
     * @param Watcher $watcher
     * @param \Vivo\Uuid\GeneratorInterface $uuidGenerator
     * @param \Vivo\IO\IOUtil $ioUtil
     * @throws Exception\Exception
     */
    public function __construct(Storage\StorageInterface $storage,
                                Cache $cache = null,
                                Indexer $indexer,
                                IndexerHelper $indexerHelper,
                                Serializer $serializer,
                                UuidConvertorInterface $uuidConvertor,
                                Watcher $watcher,
                                UuidGenerator $uuidGenerator,
                                IOUtil $ioUtil)
	{
        if ($cache) {
            //Check that cache supports all required data types
            $requiredTypes  = array('NULL', 'boolean', 'integer', 'double', 'string', 'array', 'object');
            $supportedTypes = $cache->getCapabilities()->getSupportedDatatypes();
            foreach ($requiredTypes as $requiredType) {
                if (!$supportedTypes[$requiredType]) {
                    throw new Exception\Exception(sprintf(
                        "%s: The cache used for Repository must support data type '%s'", __METHOD__, $requiredType));
                }
            }
        }
		$this->storage          = $storage;
        $this->cache            = $cache;
		$this->indexer          = $indexer;
        $this->indexerHelper    = $indexerHelper;
		$this->serializer       = $serializer;
        $this->uuidConvertor    = $uuidConvertor;
        $this->watcher          = $watcher;
        $this->uuidGenerator    = $uuidGenerator;
        $this->ioUtil           = $ioUtil;
        $this->pathBuilder      = $this->storage->getPathBuilder();
	}

	/**
	 * Returns entity from CMS repository by its identification.
	 * @param string $ident entity identification (path, UUID or symbolic reference)
	 * @param bool $throw_exception
	 * @throws Vivo\CMS\EntityNotFoundException
	 * @return Vivo\CMS\Model\Entity
     * @todo OBSOLETE
	 */
	public function getEntity_Old($ident, $throwException = true)
	{
		$uuid = null;
		if (preg_match('/^\[ref:('.self::UUID_PATTERN.')\]$/i', $ident, $matches)) {
			// symbolic reference in [ref:uuid] format
			$uuid = strtoupper($matches[1]);
		} elseif (preg_match('/^'.self::UUID_PATTERN.'$/i', $ident)) {
			// UUID
			$uuid = strtoupper($ident);
		}
		if ($uuid) {
			throw new \Exception('TODO');

			/* UUID */
			$query = new \Vivo\Indexer\Query('SELECT Vivo\CMS\Model\Entity\uuid = :uuid');
			$query->setParameter('uuid', $uuid);

			$entities = $this->indexer->execute($query);

			if (!empty($entities)) {
				return $entities[0];
			}
			if ($throwException)
				throw new Exception\EntityNotFoundException(sprintf('Entity not found; UUID: %s', $uuid));
			return null;
		} else {
			/* path */
			//$ident = str_replace('//', '/', $ident); //s prechodem na novy zapis cest se muze vyskytnout umisteni 2 lomitek
// 			$path = $ident.((substr($ident, -1) == '/') ? '' : '/').self::ENTITY_FILENAME;
// 			$cache_mtime = CMS::$cache->mtime($path);
// 			if (($cache_mtime == Util\FS\Cache::NOW) || (($storage_mtime = $this->storage->mtime($path)) && ($cache_mtime > $storage_mtime))) {
// 				// cache access
// 				$entity = CMS::$cache->get($path, false, true);
// 			} else {
// 				// repository access
// 				CMS::$cache->remove($path); // first remove entity from 2nd level cache
// 				if (!$this->storage->contains($path)) {
// 					if ($throwException)
// 						throw new CMS\EntityNotFoundException(substr($path, 0, -strlen(self::ENTITY_FILENAME)));
// 					return null;
// 				}

// 				try {
// 					$entity = Util\Object::unserialize($this->storage->get($path));
// 				} catch (\Exception $e) {
// 					if ($throwException)
// 						throw $e;
// 					return null;
// 				}
// 				CMS::$cache->set($path, $entity, true);
// 			}

// 			$entity = CMS::convertReferencesToURLs($entity);
// 			$entity->setPath(substr($path, 0, strrpos($path, '/'))); // set volatile path property of entity instance

			$path = $ident . '/' . self::ENTITY_FILENAME;
			//@todo: tohle uz obsahuje metoda get
			if($throwException && !$this->storage->contains($path)) {
				throw new \Exception(sprintf('Entity not found; ident: %s, path: %s', $ident, $path));
			}

			$entity = $this->serializer->unserialize($this->storage->get($path));
			$entity->setPath($ident); // set volatile path property of entity instance

			return $entity;
		}
	}

    /**
     * Returns entity from repository
     * If the entity does not exist, returns null
     * @param string $ident Entity identification (path, UUID or symbolic reference)
     * @return \Vivo\CMS\Model\Entity|null
     * @throws Exception\EntityNotFoundException
     */
    public function getEntity($ident)
    {
        $identArray = $this->getUuidAndPathFromEntityIdent($ident);
        $uuid   = $identArray['uuid'];
        $path   = $identArray['path'];
        if ($uuid) {
            //Get entity from watcher
            $entity = $this->watcher->get($uuid);
            if ($entity) {
                return $entity;
            }
            //Get entity from cache
            $entity = $this->getEntityFromCache($uuid);
            if ($entity) {
                return $entity;
            }
        }
        if ($path) {
            //Get entity from storage
            $entity = $this->getEntityFromStorage($path);
            if ($entity) {
                return $entity;
            }
        }
        return null;
    }

    /**
     * Looks up an entity in cache and returns it
     * If the entity does not exist in cache or the cache is not configured, returns null
     * @param string $uuid
     * @return \Vivo\CMS\Model\Entity|null
     */
    protected function getEntityFromCache($uuid)
    {
        if ($this->cache) {
            $cacheSuccess   = null;
            $entity         = $this->cache->getItem($uuid, $cacheSuccess);
            if ($cacheSuccess) {
                $this->watcher->add($entity);
            }
        } else {
            $entity = null;
        }
        return $entity;
    }

    /**
     * Looks up an entity in storage and returns it
     * If the entity does not exist, returns null
     * @param string $path
     * @return \Vivo\CMS\Model\Entity|null
     */
    protected function getEntityFromStorage($path)
    {
        $pathComponents = array($path, self::ENTITY_FILENAME);
        $fullPath       = $this->pathBuilder->buildStoragePath($pathComponents, true);
        if ($this->storage->isObject($fullPath)) {
            $entitySer      = $this->storage->get($fullPath);
            $entity         = $this->serializer->unserialize($entitySer);
            /* @var $entity \Vivo\CMS\Model\Entity */
            $entity->setPath($path); // set volatile path property of entity instance
            $this->watcher->add($entity);
            if ($this->cache) {
                $this->cache->setItem($entity->getUuid(), $entity);
            }
        } else {
            $entity = null;
        }
        return $entity;
    }

	/**
     * Returns parent folder
     * If there is no parent folder (ie this is a root), returns null
	 * @param \Vivo\CMS\Model\Folder $folder
	 * @return \Vivo\CMS\Model\Folder
	 */
	public function getParent(Model\Folder $folder)
	{
        $pathElements       = $this->pathBuilder->getStoragePathComponents($folder->getPath());
        if (count($pathElements) == 0) {
            //$folder is a root folder
            return null;
        }
        array_pop($pathElements);
        $parentFolderPath   = $this->pathBuilder->buildStoragePath($pathElements, true);
        $parentFolder       = $this->getEntity($parentFolderPath);
		return $parentFolder;
	}

    /**
     * Return subdocuments
     * When $deep == true, returns descendants rather than children
     * @param \Vivo\CMS\Model\Entity $entity
     * @param bool|string $className
     * @param bool $deep
     * @return \Vivo\CMS\Model\Entity[]
     */
    public function getChildren(Model\Entity $entity, $className = false, $deep = false)
	{
		$children       = array();
		$descendants    = array();
		$path           = $entity->getPath();
		//TODO: tady se zamyslet, zda neskenovat podle tridy i obsahy
		//if (is_subclass_of($class_name, 'Vivo\Cms\Model\Content')) {
		//	$names = $this->storage->scan("$path/Contents");
		//}
		//else {
			$names = $this->storage->scan($path);
		//}
		sort($names); // sort it in a natural way

		foreach ($names as $name) {
            $childPath  = $this->pathBuilder->buildStoragePath(array($path, $name), true);
			if (!$this->storage->isObject($childPath)) {
				$entity = $this->getEntity($childPath);
				if ($entity /* && ($entity instanceof CMS\Model\Site || CMS::$securityManager->authorize($entity, 'Browse', false))*/) {
				    $children[] = $entity;
                }
			}
		}

		// sorting
// 		$entity = $this->getEntity($path, false);
// 		if ($entity instanceof CMS\Model\Entity) {
			//@todo: sorting? jedine pre Interface "SortableInterface" ?
// 			$sorting = method_exists($entity, 'sorting') ? $entity->sorting() : $entity->sorting;
// 			if (Util\Object::is_a($sorting, 'Closure')) {
// 				usort($children, $sorting);
// 			} elseif (is_string($sorting)) {
// 				$cmp_function = 'cmp_'.str_replace(' ', '_', ($rpos = strrpos($sorting, '_')) ? substr($sorting, $rpos + 1) : $sorting);
// 				if (method_exists($entity, $cmp_function)) {
// 					usort($children, array($entity, $cmp_function));
// 				}
// 			}
// 		}

		foreach ($children as $child) {
            if (!$className || $child instanceof $className) {
                $descendants[]  = $child;
            }
            //All descendants
			if ($deep) {
                $childDescendants   = $this->getChildren($child, $className, $deep);
                $descendants        = array_merge($descendants, $childDescendants);
			}
		}
		return $descendants;
	}

	/**
     * Returns true when the folder has children
	 * @param Model\Folder $folder
	 * @return bool
	 */
	public function hasChildren(Model\Folder $folder)
	{
		$path = $folder->getPath();
		foreach ($this->storage->scan($path) as $name) {
            $pathElements   = array($path, $name, self::ENTITY_FILENAME);
            $childPath      = $this->pathBuilder->buildStoragePath($pathElements);
			if ($this->storage->contains($childPath)) {
				return true;
			}
		}
		return false;
	}

    /**
     * Saves entity state to repository.
     * Changes become persistent when commit method is called within request.
     * @param \Vivo\CMS\Model\Entity $entity Entity to save
     * @throws Exception\Exception
     * @return \Vivo\CMS\Model\Entity
     */
	public function saveEntity(Model\Entity $entity)
	{
        $entityPath = $entity->getPath();
		if (!$entityPath) {
			throw new Exception\Exception(
                sprintf("%s: Entity with UUID = '%s' has no path set", __METHOD__, $entity->getUuid()));
		}
		//@todo: tohle nemelo by vyhazovat exception, misto zmeny path? - nema tohle resit jina metoda,
        //treba pathValidator; pak asi entitu ani nevracet
        //TODO - possible collisions, PathValidator or revise the path validation in general
		$path   = '';
		$len    = strlen($entityPath);
		for ($i = 0; $i < $len; $i++) {
			$path .= (stripos('abcdefghijklmnopqrstuvwxyz0123456789-_/.', $entityPath{$i}) !== false)
					? $entityPath{$i} : '-';
		}
		$entity->setPath($path);
        $this->saveEntities[$path]  = $entity;
        return $entity;
	}

	/**
	 * Returns site entity by host name.
	 * @param string $host
	 * @return Vivo\CMS\Model\Site|null
	 */
	function getSiteByHost($host)
	{
		foreach ($this->getChildren(new Model\Folder('')) as $site) {
			if (in_array($host, $site->getHosts())) {
				return $site;
			}
		}
		return null;
	}

    /**
     * Adds a stream to the list of streams to be saved
     * @param \Vivo\CMS\Model\Entity $entity
     * @param string $name
     * @param \Vivo\IO\InputStreamInterface $stream
     * @return void
     */
    public function writeResource(Model\Entity $entity, $name, \Vivo\IO\InputStreamInterface $stream)
	{
        $pathComponents             = array($entity->getPath(), $name);
		$path                       = $this->pathBuilder->buildStoragePath($pathComponents, true);
        $this->saveStreams[$path]   = $stream;
	}

    /**
     * Adds an entity resource (data) to the list of resources to be saved
     * @param \Vivo\CMS\Model\Entity $entity
     * @param string $name Name of resource
     * @param string $data
     */
    public function saveResource(Model\Entity $entity, $name, $data)
	{
        $pathComponents         = array($entity->getPath(), $name);
        $path                   = $this->pathBuilder->buildStoragePath($pathComponents, true);
        $this->saveData[$path]  = $data;
	}

	/**
	 * @param \Vivo\CMS\Model\Entity $entity
	 * @param string $name Resource file name.
	 * @return \Vivo\IO\InputStreamInterface
	 */
	public function readResource(Model\Entity $entity, $name)
	{
        $pathComponents = array($entity->getPath(), $name);
        $path           = $this->pathBuilder->buildStoragePath($pathComponents, true);
        $stream         = $this->storage->read($path);
		return $stream;
	}

	/**
	 * @param \Vivo\CMS\Model\Entity $entity
	 * @param string $name
	 * @return string
	 */
	public function getResource(Model\Entity $entity, $name)
	{
        $pathComponents = array($entity->getPath(), $name);
        $path           = $this->pathBuilder->buildStoragePath($pathComponents, true);
        $data           = $this->storage->get($path);
		return $data;
	}

    /**
     * Utility method for Vivo\CMS\Event
     * @param \Vivo\CMS\Model\Document $document
     * @return array
     */
    public function getAllContents(Model\Document $document)
	{
		$return = array();
// 		if($entity instanceof CMS\Model\Document) {
		//@todo:
			$count = $document->getContentCount();
			for ($index = 1; $index <= $count; $index++) {
				$return = array_merge($return, $document->getContents($index));
			}
// 		}
		return $return;
	}

    /**
     * Adds an entity to the list of entities to be deleted
     * @param \Vivo\CMS\Model\Entity $entity
     */
    public function deleteEntity(Model\Entity $entity)
	{
		//TODO - check that the entity is empty
        $this->deleteEntities[]     = $entity;
	}

    /**
     * Adds an entity's resource to the list of resources to be deleted
     * @param \Vivo\CMS\Model\Entity $entity
     * @param string $name Name of the resource
     */
    public function deleteResource(Model\Entity $entity, $name)
	{
        $pathComponents         = array($entity->getPath(), $name);
        $path                   = $this->pathBuilder->buildStoragePath($pathComponents, true);
        $this->deletePaths[]    = $path;
	}

	/**
	 * @todo
	 *
	 * @param Vivo\CMS\Model\Entity $entity
	 * @param string $target Target path.
	 */
	public function moveEntity(Model\Entity $entity, $target) { }

	/**
	 * @todo
	 *
	 * @param Vivo\CMS\Model\Entity $entity
	 * @param string $target Target path.
	 */
	public function copyEntity(Model\Entity $entity, $target) { }

	/**
	 * @param string $path Source path.
	 * @param string $target Destination path.
	 */
// 	function move($path, $target) {
// 		if (strpos($target, "$path/") === 0)
// 			throw new CMS\Exception(500, 'recursive_operation', array($path, $target));
// 		$path2 = str_replace(' ', '\\ ', $path);
// 		$this->indexer->deleteByQuery("vivo_cms_model_entity_path:$path2 OR vivo_cms_model_entity_path:$path2/*");
// 		$entity = $this->getEntity($path);
// 		if (method_exists($entity, 'beforeMove')) {
// 			$entity->beforeMove($target);
// 			CMS::$logger->warn('Method '.get_class($entity).'::geforeMove() is deprecated. Use Vivo\CMS\Event methods instead.');
// 		}
// 		$this->callEventOn($entity, CMS\Event::ENTITY_BEFORE_MOVE);
// 		$this->storage->move($path, $target);
// 		$targetEntity = $this->getEntity($target);
// 		$targetEntity->path = rtrim($target, '/'); // @fixme: tady by melo dojit nejspis ke smazani kese, tak aby nova entita mela novou cestu a ne starou
// 		$this->callEventOn($targetEntity, CMS\Event::ENTITY_AFTER_MOVE);
// 		//CMS::$cache->clear_mem(); //@fixme: Dodefinovat metodu v Cache tridach - nestaci definice v /FS/Cache, ale i do /FS/DB/Cache - definovat ICache
// 		$this->reindex($targetEntity, true);
// 		$this->indexer->commit();
// 		return $targetEntity;
// 	}

	/**
	 * @param string $path Source path.
	 * @param string $target Destination path.
	 * @throws Vivo\CMS\Exception 500, Recursive operation
	 */
// 	function copy($path, $target) {
// 		if (strpos($target, "$path/") === 0)
// 			throw new CMS\Exception(500, 'recursive_operation', array($path, $target));
// 		$entity = $this->getEntity($path);
// 		CMS::$securityManager->authorize($entity, 'Copy');
// 		if (method_exists($entity, 'beforeCopy')) {
// 			$entity->beforeCopy($target);
// 			CMS::$logger->warn('Method '.get_class($entity).'::geforeCopy() is deprecated. Use Vivo\CMS\Event methods instead.');
// 		}
// 		$this->callEventOn($entity, CMS\Event::ENTITY_BEFORE_COPY);
// 		$this->storage->copy($path, $target);
// 		if ($entity = $this->getEntity($target, false)) {
// 			if ($entity->title)
// 				$entity->title .= ' COPY';
// 			$this->copy_entity($entity);
// 			$this->commit();
// 			$this->reindex($entity);
// 		}
// 		return $entity;
// 	}

	/**
	 * @param Vivo\CMS\Model\Entity $entity
	 */
    /*
	private function copy_entity($entity) {
		$entity->uuid = CMS\Model\Entity::create_uuid();
		$entity->created = $entity->modified = $entity->published = CMS::$current_time;
		$entity->createdBy = $entity->modifiedBy =
			($user = CMS::$securityManager->getUserPrincipal()) ?
				"{$user->domain}\\{$user->username}" :
				Context::$instance->site->domain.'\\'.Security\Manager::USER_ANONYMOUS;
// 		if (method_exists($entity, 'afterCopy')) {
// 			$entity->afterCopy();
// 			CMS::$logger->warn('Method '.get_class($entity).'::afterCopy() is deprecated. Use Vivo\CMS\Event methods instead.');
// 		}
		CMS::$event->invoke(CMS\Event::ENTITY_AFTER_COPY, $entity);
		$this->saveEntity($entity);
		foreach($this->getAllContents($entity) as $content) {
			$this->copy_entity($content);
		}
		foreach ($entity->getChildren() as $child) {
			$this->copy_entity($child);
		}
	}
    */

    public function reindexAll()
    {
        //TODO - implement
    }

    /**
     * Reindex all entities (contents and children) saved under entity
     * Returns number of reindexed items
     * @param string $path Path to entity
     * @param bool $deep If true reindexes whole subtree
     * @return int
     */
    public function reindex($path, $deep = false)
	{
//        throw new \Exception(sprintf('%s: method not refactored yet', __METHOD__));
        //TODO - undefined Entity methods
        $this->indexer->begin();
        //TODO - try block ?
        if ($deep) {
            $delQuery   = $this->indexerHelper->buildTreeQuery($path);
            $this->indexer->delete($delQuery);
        } else {
            $term       = new IndexerTerm($path, 'path');
            $this->indexer->deleteByTerm($term);
        }




        //TODO - continue here




        $entity     = $this->getEntity($uuid);
        $idxDoc     = $this->indexerHelper->createDocument($entity);
        $this->indexer->addDocument($idxDoc);
        $count      = 1;

        //TODO - reindex entity Contents
//		if ($entity instanceof Vivo\CMS\Model\Document) {
//            /* @var $entity \Vivo\CMS\Model\Document */
//			for ($index = 1; $index <= $entity->getContentCount(); $index++)
//				foreach ($entity->getContents($index) as $content)
//					$count += $this->reindex($content, true);
//		}
		if ($deep)
            $children   = $this->getChildren($entity);
			foreach ($children as $child)
				$count += $this->reindex($child, $deep);

        $this->indexer->commit();

		return $count;
	}

    /**
     * Begins transaction
     * A transaction is always open, do not call 'begin()' explicitly
     */
    public function begin()
    {
        throw new Exception\Exception("%s: A transaction is always open, do not call 'begin()' explicitly", __METHOD__);
    }

    /**
     * Commit commits the current transaction, making its changes permanent
     */
    public function commit()
    {
        try {
            //Open indexer transaction
            $this->indexer->begin();

            //Delete - Phase 1 (move to temp files)
            //a) Entities
            foreach ($this->deleteEntities as $entity) {
                $path                       = $entity->getPath();
                //TODO - check the path is not null!
                $tmpPath                    = $this->pathBuilder->buildStoragePath(array(uniqid('del-')), true);
                $this->tmpDelFiles[$path]   = $tmpPath;
                $this->storage->move($path, $tmpPath);
            }
            //b) Resources
            foreach ($this->deletePaths as $path) {
                $tmpPath                    = $this->pathBuilder->buildStoragePath(array(uniqid('del-')), true);
                $this->tmpDelFiles[$path]   = $tmpPath;
                $this->storage->move($path, $tmpPath);
            }
            //Save - Phase 1 (serialize entities and files into temp files)
            //a) Entity
            $now = new \DateTime();
            foreach ($this->saveEntities as $entity) {
                if (!$entity->getCreated() instanceof \DateTime) {
                    $entity->setCreated($now);
                }
                if (!$entity->getCreatedBy()) {
                    //TODO - what to do when an entity does not have its creator set?
                    //$entity->setCreatedBy($username);
                }
                if(!$entity->getUuid()) {
                    $entity->setUuid($this->uuidGenerator->create());
                }
                $entity->setModified($now);
                //TODO - set entity modifier
                //$entity->setModifiedBy($username);
                $pathElements           = array($entity->getPath(), self::ENTITY_FILENAME);
                $path                   = $this->pathBuilder->buildStoragePath($pathElements, true);
                $tmpPath                = $path . '.' . uniqid('tmp-');
                $this->tmpFiles[$path]  = $tmpPath;
                $entitySer              = $this->serializer->serialize($entity);
                $this->storage->set($tmpPath, $entitySer);
            }
            //b) Data
            foreach ($this->saveData as $path => $data) {
                $tmpPath                = $path . '.' . uniqid('tmp-');
                $this->tmpFiles[$path]  = $tmpPath;
                $this->storage->set($tmpPath, $data);
            }
            //c) Streams
            foreach ($this->saveStreams as $path => $stream) {
                $tmpPath                = $path . '.' . uniqid('tmp-');
                $this->tmpFiles[$path]  = $tmpPath;
                $output                 = $this->storage->write($tmpPath);
                $this->ioUtil->copy($stream, $output, 1024);
            }
            //Delete - Phase 2 (delete the temp files) - this is done in removeTempFiles
            //Save Phase 2 (rename temp files to real ones)
            foreach ($this->tmpFiles as $path => $tmpPath) {
                if (!$this->storage->move($tmpPath, $path)) {
                    throw new Exception\Exception(
                        sprintf("%s: Move failed; source: '%s', destination: '%s'", __METHOD__, $tmpPath, $path));
                }
            }

            //The actual commit is successfully done, now process references to entities in Watcher, Cache, Indexer, etc

            //Delete entities from Watcher, Cache and Indexer, remove cached results from UuidConverter
 			foreach ($this->deleteEntities as $entity) {
                $uuid   = $entity->getUuid();
                if ($uuid) {
                    //Watcher
                    $this->watcher->remove($uuid);
                    //Cache
                    if ($this->cache) {
                        $this->cache->removeItem($uuid);
                    }
                    //UuidConvertor
                    $this->uuidConvertor->removeByUuid($uuid);
                }
                //Indexer
                $delQuery   = $this->indexerHelper->buildTreeQuery($entity);
                $this->indexer->delete($delQuery);
 			}
            //Save entities to Watcher, Cache and Indexer, update cached results in UuidConverter
 			foreach ($this->saveEntities as $entity) {
                //Watcher
                $this->watcher->add($entity);
                //Cache
                if ($this->cache) {
                    $this->cache->setItem($entity->getUuid(), $entity);
                }
                //Indexer - remove old doc insert new one
                $entityTerm = $this->indexerHelper->buildEntityTerm($entity);
                $entityDoc  = $this->indexerHelper->createDocument($entity);
                $this->indexer->deleteByTerm($entityTerm);
                $this->indexer->addDocument($entityDoc);
                //UuidConvertor
                $this->uuidConvertor->set($entity->getUuid(), $entity->getPath());
            }

            //Clean-up after commit
            $this->reset();

            //Commit changes to index
            $this->indexer->commit();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Resets/clears the changes scheduled for this transaction
     * Calling this function on uncommitted transaction may lead to data loss!
     */
    protected function reset()
    {
        $this->copyFiles            = array();
        $this->deletePaths          = array();
        $this->deleteEntities       = array();
        $this->saveData             = array();
        $this->saveEntities         = array();
        $this->saveStreams          = array();
        $this->removeTempFiles();
    }

    /**
     * Rollback rolls back the current transaction, canceling changes
     */
    public function rollback()
    {
        //Rollback indexer changes
        $this->indexer->rollback();
        //Move back everything that was moved during Delete Phase 1
        foreach ($this->tmpDelFiles as $path => $tmpPath) {
            try {
                $this->storage->move($tmpPath, $path);
            } catch (\Exception $e) {
                //Just continue
            }
        }
        //Delete remaining temp files - done by reset()
        $this->reset();
    }

    /**
     * Removes temporary files created during commit
     */
    protected function removeTempFiles()
    {
        //TempDelFiles
        foreach ($this->tmpDelFiles as $tmpPath) {
            try {
                if ($this->storage->contains($tmpPath)) {
                    $this->storage->remove($tmpPath);
                }
            } catch (\Exception $e) {
                //Just continue, we should try to remove all temp files even though an exception has been thrown
            }
        }
        $this->tmpDelFiles          = array();
        //TempFiles
        foreach ($this->tmpFiles as $path) {
            try {
                if ($this->storage->contains($path)) {
                    $this->storage->remove($path);
                }
            } catch (\Exception $e) {
                //Just continue, we should try to remove all temp files even though an exception has been thrown
            }
        }
        $this->tmpFiles             = array();
    }

    /**
     * Returns array('uuid' => ..., 'path' => ...) with uuid and path
     * Either returned element may be null
     * @param string $ident UUID, path or ref
     * @return array
     */
    protected function getUuidAndPathFromEntityIdent($ident)
    {
        $uuid   = null;
        $path   = null;
        if (preg_match('/^'.self::UUID_PATTERN.'$/i', $ident)) {
            //UUID
            $uuid = strtoupper($ident);
        } elseif (preg_match('/^\[ref:('.self::UUID_PATTERN.')\]$/i', $ident, $matches)) {
            //Symbolic reference in [ref:uuid] format
            $uuid = strtoupper($matches[1]);
        } else {
            //Attempt conversion from path
            $uuid = $this->uuidConvertor->getUuid($ident);
            if ($uuid) {
                $path = $ident;
            }
        }
//        if (!$uuid) {
//            throw new Exception\EntityNotFoundException(
//                sprintf("%s: Cannot get UUID for entity identifier '%s'", __METHOD__, $ident));
//        }
        if (!$path && $uuid) {
            $path = $this->uuidConvertor->getPath($uuid);
//            if (!$path) {
//                throw new Exception\EntityNotFoundException(
//                    sprintf("%s: Cannot get path for UUID = '%s'", __METHOD__, $uuid));
//            }
        }
        $result = array(
            'uuid'  => $uuid,
            'path'  => $path,
        );
        return $result;
    }
}
