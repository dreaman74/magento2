<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\AsynchronousOperations\Model;

use Magento\AsynchronousOperations\Model\ResourceModel\Bulk\CollectionFactory as BulkCollectionFactory;
use Magento\AsynchronousOperations\Model\ResourceModel\Operation\CollectionFactory as OperationCollectionFactory;
use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\AsynchronousOperations\Api\Data\BulkSummaryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\AsynchronousOperations\Model\BulkStatus\CalculatedStatusSql;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\EntityManager\EntityManager;
use Magento\AsynchronousOperations\Api\Data\BulkStatusInterfaceFactory as BulkStatusShortFactory;
use Magento\AsynchronousOperations\Api\Data\DetailedBulkStatusInterfaceFactory as BulkStatusDetailedFactory;
use Magento\AsynchronousOperations\Api\Data\OperationDetailsInterfaceFactory;
use Magento\AsynchronousOperations\Api\BulkStatusInterface;

/**
 * Class BulkStatus
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class BulkOperationsStatus implements BulkStatusInterface
{
    /**
     * @var \Magento\AsynchronousOperations\Api\Data\BulkSummaryInterfaceFactory
     */
    private $bulkCollectionFactory;

    /**
     * @var \Magento\AsynchronousOperations\Api\Data\OperationInterfaceFactory
     */
    private $operationCollectionFactory;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var CalculatedStatusSql
     */
    private $calculatedStatusSql;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var BulkStatusDetailedFactory
     */
    private $bulkDetailedFactory;

    /**
     * @var BulkStatusShortFactory
     */
    private $bulkShortFactory;

    /**
     * Init dependencies.
     *
     * @param \Magento\AsynchronousOperations\Model\ResourceModel\Bulk\CollectionFactory $bulkCollection
     * @param \Magento\AsynchronousOperations\Model\ResourceModel\Operation\CollectionFactory $operationCollection
     * @param \Magento\Framework\App\ResourceConnection $resourceConnection
     * @param \Magento\AsynchronousOperations\Model\BulkStatus\CalculatedStatusSql $calculatedStatusSql
     * @param \Magento\Framework\EntityManager\MetadataPool $metadataPool
     * @param BulkStatusDetailedFactory $bulkDetailedFactory
     * @param BulkStatusShortFactory $bulkShortFactory
     * @param \Magento\Framework\EntityManager\EntityManager $entityManager
     */
    public function __construct(
        BulkCollectionFactory $bulkCollection,
        OperationCollectionFactory $operationCollection,
        ResourceConnection $resourceConnection,
        CalculatedStatusSql $calculatedStatusSql,
        MetadataPool $metadataPool,
        BulkStatusDetailedFactory $bulkDetailedFactory,
        BulkStatusShortFactory $bulkShortFactory,
        EntityManager $entityManager
    ) {
        $this->operationCollectionFactory = $operationCollection;
        $this->bulkCollectionFactory = $bulkCollection;
        $this->resourceConnection = $resourceConnection;
        $this->calculatedStatusSql = $calculatedStatusSql;
        $this->metadataPool = $metadataPool;
        $this->bulkDetailedFactory = $bulkDetailedFactory;
        $this->bulkShortFactory = $bulkShortFactory;
        $this->entityManager = $entityManager;
    }

    /**
     * @inheritDoc
     */
    public function getFailedOperationsByBulkId($bulkUuid, $failureType = null)
    {
        $failureCodes = $failureType
            ? [$failureType]
            : [
                OperationInterface::STATUS_TYPE_RETRIABLY_FAILED,
                OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED,
            ];
        $operations = $this->operationCollectionFactory->create()
            ->addFieldToFilter('bulk_uuid', $bulkUuid)
            ->addFieldToFilter('status', $failureCodes)
            ->getItems();

        return $operations;
    }

    /**
     * @inheritDoc
     */
    public function getOperationsCountByBulkIdAndStatus($bulkUuid, $status)
    {

        /** @var \Magento\AsynchronousOperations\Model\ResourceModel\Operation\Collection $collection */
        $collection = $this->operationCollectionFactory->create();

        return $collection->addFieldToFilter('bulk_uuid', $bulkUuid)
            ->addFieldToFilter('status', $status)
            ->getSize();
    }

    /**
     * @inheritDoc
     */
    public function getBulksByUser($userId)
    {
        /** @var ResourceModel\Bulk\Collection $collection */
        $collection = $this->bulkCollectionFactory->create();
        $operationTableName = $this->resourceConnection->getTableName('magento_operation');
        $statusesArray = [
            OperationInterface::STATUS_TYPE_RETRIABLY_FAILED,
            OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED,
            BulkSummaryInterface::NOT_STARTED,
            OperationInterface::STATUS_TYPE_OPEN,
            OperationInterface::STATUS_TYPE_COMPLETE,
        ];
        $select = $collection->getSelect();
        $select->columns(['status' => $this->calculatedStatusSql->get($operationTableName)])
            ->order(
                new \Zend_Db_Expr(
                    'FIELD(status, ' . implode(',', $statusesArray) . ')'
                )
            );
        $collection->addFieldToFilter('user_id', $userId)
            ->addOrder('start_time');

        return $collection->getItems();
    }

    /**
     * @inheritDoc
     */
    public function getBulkStatus($bulkUuid)
    {
        /**
         * Number of operations that has been processed (i.e. operations with any status but 'open')
         */
        $allProcessedOperationsQty = (int)$this->operationCollectionFactory->create()
            ->addFieldToFilter('bulk_uuid', $bulkUuid)
            ->getSize();

        if ($allProcessedOperationsQty == 0) {
            return BulkSummaryInterface::NOT_STARTED;
        }

        /**
         * Total number of operations that has been scheduled within the given bulk
         */
        $allOperationsQty = $this->getOperationCount($bulkUuid);

        /**
         * Number of operations that has not been started yet (i.e. operations with status 'open')
         */
        $allOpenOperationsQty = $allOperationsQty - $allProcessedOperationsQty;

        /**
         * Number of operations that has been completed successfully
         */
        $allCompleteOperationsQty = $this->operationCollectionFactory
            ->create()
            ->addFieldToFilter('bulk_uuid', $bulkUuid)
            ->addFieldToFilter(
                'status',
                OperationInterface::STATUS_TYPE_COMPLETE
            )
            ->getSize();

        if ($allCompleteOperationsQty == $allOperationsQty) {
            return BulkSummaryInterface::FINISHED_SUCCESSFULLY;
        }

        if ($allOpenOperationsQty > 0 && $allOpenOperationsQty !== $allOperationsQty) {
            return BulkSummaryInterface::IN_PROGRESS;
        }

        return BulkSummaryInterface::FINISHED_WITH_FAILURE;
    }

    /**
     * Get total number of operations that has been scheduled within the given bulk.
     *
     * @param string $bulkUuid
     * @return int
     */
    private function getOperationCount($bulkUuid)
    {
        $metadata = $this->metadataPool->getMetadata(BulkSummaryInterface::class);
        $connection = $this->resourceConnection->getConnectionByName($metadata->getEntityConnectionName());

        return (int)$connection->fetchOne(
            $connection->select()
                ->from($metadata->getEntityTable(), 'operation_count')
                ->where('uuid = ?', $bulkUuid)
        );
    }

    /**
     * @inheritDoc
     */
    public function getBulkDetailedStatus($bulkUuid)
    {
        $bulkSummary = $this->bulkDetailedFactory->create();

        /** @var \Magento\AsynchronousOperations\Api\Data\DetailedBulkStatusInterface $bulk */
        $bulk = $this->entityManager->load($bulkSummary, $bulkUuid);

        if ($bulk->getBulkId() === null) {
            throw new NoSuchEntityException(
                __(
                    'Bulk uuid %bulkUuid not exist',
                    ['bulkUuid' => $bulkUuid]
                )
            );
        }
        $operations = $this->operationCollectionFactory->create()->addFieldToFilter('bulk_uuid', $bulkUuid)->getItems();
        $bulk->setOperationsList($operations);

        return $bulk;
    }

    /**
     * @inheritDoc
     */
    public function getBulkShortStatus($bulkUuid)
    {
        $bulkSummary = $this->bulkShortFactory->create();

        /** @var \Magento\AsynchronousOperations\Api\Data\BulkStatusInterface $bulk */
        $bulk = $this->entityManager->load($bulkSummary, $bulkUuid);
        if ($bulk->getBulkId() === null) {
            throw new NoSuchEntityException(
                __(
                    'Bulk uuid %bulkUuid not exist',
                    ['bulkUuid' => $bulkUuid]
                )
            );
        }
        $operations = $this->operationCollectionFactory->create()->addFieldToFilter('bulk_uuid', $bulkUuid)->getItems();
        $bulk->setOperationsList($operations);

        return $bulk;
    }
}
