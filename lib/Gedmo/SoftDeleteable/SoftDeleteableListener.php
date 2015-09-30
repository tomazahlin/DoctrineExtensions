<?php

namespace Gedmo\SoftDeleteable;

use DateTime;
use Doctrine\ORM\Mapping\ClassMetadata;
use Gedmo\Mapping\MappedEventSubscriber;
use Doctrine\Common\EventArgs;
use Doctrine\ODM\MongoDB\UnitOfWork as MongoDBUnitOfWork;

/**
 * SoftDeleteable listener
 *
 * @author Gustavo Falco <comfortablynumb84@gmail.com>
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class SoftDeleteableListener extends MappedEventSubscriber
{
    /**
     * Pre soft-delete event
     *
     * @var string
     */
    const PRE_SOFT_DELETE = "preSoftDelete";

    /**
     * Post soft-delete event
     *
     * @var string
     */
    const POST_SOFT_DELETE = "postSoftDelete";

    /**
     * @var ClassMetadata
     */
    private $meta;

    /**
     * @var array
     */
    private $config;

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents()
    {
        return array(
            'loadClassMetadata',
            'onFlush',
        );
    }

    /**
     * If it's a SoftDeleteable object, update the "deleted" field
     * and skip the removal of the object
     *
     * @param EventArgs $args
     *
     * @return void
     */
    public function onFlush(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        $evm = $om->getEventManager();

        //getScheduledDocumentDeletions
        foreach ($ea->getScheduledObjectDeletions($uow) as $object) {
            $this->meta = $om->getClassMetadata(get_class($object));
            $this->config = $this->getConfiguration($om, $this->meta->name);

            if (isset($this->config['softDeleteable']) && $this->config['softDeleteable']) {
                $reflProp = $this->meta->getReflectionProperty($this->config['fieldName']);
                $oldValue = $reflProp->getValue($object);
                if ($this->isSoftDeleted($oldValue)) {
                    continue; // want to hard delete
                }

                $evm->dispatchEvent(
                    self::PRE_SOFT_DELETE,
                    $ea->createLifecycleEventArgsInstance($object, $om)
                );

                if ($this->meta->getTypeOfField($this->config['fieldName']))
                    $newValue = $this->createNewType();
                $reflProp->setValue($object, $newValue);

                $om->persist($object);
                $uow->propertyChanged($object, $this->config['fieldName'], $oldValue, $newValue);
                if ($uow instanceof MongoDBUnitOfWork && !method_exists($uow, 'scheduleExtraUpdate')) {
                    $ea->recomputeSingleObjectChangeSet($uow, $this->meta, $object);
                } else {
                    $uow->scheduleExtraUpdate($object, array(
                        $this->config['fieldName'] => array($oldValue, $newValue),
                    ));
                }

                $evm->dispatchEvent(
                    self::POST_SOFT_DELETE,
                    $ea->createLifecycleEventArgsInstance($object, $om)
                );
            }
        }
    }

    /**
     * @param $oldValue
     * @return bool
     */
    private function isSoftDeleted($oldValue)
    {
        if ($this->isDateTimeType() && $oldValue instanceof Datetime) {
            return true;
        }

        if ($this->isBooleanType() && $oldValue === true) {
            return true;
        }

        return false;
    }

    /**
     * @return DateTime|bool
     */
    private function createNewType()
    {
        if ($this->isDateTimeType()) {
            return new DateTime();
        }

        if ($this->isBooleanType()) {
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    private function isDateTimeType()
    {
        return $this->meta->getTypeOfField($this->config['fieldName']) === 'datetime';
    }

    /**
     * @return bool
     */
    private function isBooleanType()
    {
        return !$this->isDateTimeType();
    }

    /**
     * Maps additional metadata
     *
     * @param EventArgs $eventArgs
     *
     * @return void
     */
    public function loadClassMetadata(EventArgs $eventArgs)
    {
        $ea = $this->getEventAdapter($eventArgs);
        $this->loadMetadataForObjectClass($ea->getObjectManager(), $eventArgs->getClassMetadata());
    }

    /**
     * {@inheritDoc}
     */
    protected function getNamespace()
    {
        return __NAMESPACE__;
    }
}
