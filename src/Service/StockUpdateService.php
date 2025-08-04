<?php declare(strict_types=1);

namespace SwagAdvancedSyncAPI\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Events\InvalidateProductCache;
use Shopware\Core\Content\Product\Events\ProductNoLongerAvailableEvent;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class StockUpdateService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    /**
     * @param array<array{id?: string, productNumber?: string, stock: int, threshold?: int}> $updates
     * @return array<string, array{oldStock: int, newStock: int}>
     */
    public function updateStock(array $updates, Context $context): array
    {
        // Only operate on live version like StockStorage does
        if ($context->getVersionId() !== Defaults::LIVE_VERSION) {
            return [];
        }

        $productIds = [];
        $productNumberToId = [];
        $results = [];
        $versionId = Uuid::fromHexToBytes($context->getVersionId());

        // Resolve product numbers to IDs and collect all product IDs
        foreach ($updates as $update) {
            if (isset($update['id'])) {
                $productIds[] = $update['id'];
            } elseif (isset($update['productNumber'])) {
                $productNumberToId[$update['productNumber']] = null;
            }
        }

        // Resolve product numbers to IDs
        if (!empty($productNumberToId)) {
            $productNumberResults = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(id)) as id, product_number FROM product WHERE product_number IN (:productNumbers) AND version_id = :version',
                ['productNumbers' => array_keys($productNumberToId), 'version' => $versionId],
                ['productNumbers' => ArrayParameterType::STRING]
            );

            foreach ($productNumberResults as $result) {
                $productNumberToId[$result['product_number']] = $result['id'];
                $productIds[] = $result['id'];
            }
        }

        // Fetch current stock for all products
        $currentStocks = $this->connection->fetchAllKeyValue(
            'SELECT LOWER(HEX(id)), stock FROM product WHERE id IN (:ids) AND version_id = :version',
            ['ids' => Uuid::fromHexToBytesList($productIds), 'version' => $versionId],
            ['ids' => ArrayParameterType::BINARY]
        );

        $noLongerAvailableIds = [];
        $nowAvailableIds = [];
        $thresholdExceededIds = [];

        $this->connection->beginTransaction();

        // Process updates
        foreach ($updates as $update) {
            $productId = null;

            if (isset($update['id'])) {
                $productId = $update['id'];
            } elseif (isset($update['productNumber'])) {
                $productId = $productNumberToId[$update['productNumber']];
            }

            if (!$productId || !isset($currentStocks[$productId])) {
                continue;
            }

            $oldStock = (int) $currentStocks[$productId];
            $newStock = (int) $update['stock'];

            if ($oldStock === $newStock) {
                continue;
            }

            // Update the stock
            $this->connection->update(
                'product',
                ['stock' => $newStock],
                ['id' => Uuid::fromHexToBytes($productId), 'version_id' => $versionId]
            );

            $results[$productId] = [
                'oldStock' => $oldStock,
                'newStock' => $newStock,
            ];

            // Check if product is no longer available (was available, now not)
            if ($oldStock > 0 && $newStock <= 0) {
                $noLongerAvailableIds[] = $productId;
            }

            // Check if product became available (was not available, now available)
            if ($oldStock <= 0 && $newStock > 0) {
                $nowAvailableIds[] = $productId;
            }
            
            // Check if stock exceeded threshold (if threshold was provided)
            if (isset($update['threshold'])) {
                $threshold = (int) $update['threshold'];
                if ($oldStock <= $threshold && $newStock > $threshold) {
                    $thresholdExceededIds[] = $productId;
                }
            }
        }

        $this->connection->commit();

        // Dispatch event for products that are no longer available
        if (!empty($noLongerAvailableIds)) {
            $this->eventDispatcher->dispatch(
                new ProductNoLongerAvailableEvent($noLongerAvailableIds, $context)
            );
        }

        // Dispatch event for products that became available (cache invalidation)
        if (!empty($nowAvailableIds)) {
            $this->eventDispatcher->dispatch(
                new InvalidateProductCache($nowAvailableIds)
            );
        }
        
        // Dispatch event for products that exceeded threshold (cache invalidation)
        if (!empty($thresholdExceededIds)) {
            $this->eventDispatcher->dispatch(
                new InvalidateProductCache($thresholdExceededIds)
            );
        }

        return $results;
    }
}