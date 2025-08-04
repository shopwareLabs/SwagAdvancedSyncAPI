<?php declare(strict_types=1);

namespace SwagAdvancedSyncAPI\Tests\Integration\Api;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\Events\InvalidateProductCache;
use Shopware\Core\Content\Product\Events\ProductNoLongerAvailableEvent;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\AdminFunctionalTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class StockUpdateControllerTest extends TestCase
{
    use AdminFunctionalTestBehaviour;

    /**
     * @var EntityRepository<ProductCollection>
     */
    private EntityRepository $productRepository;

    private EventDispatcherInterface $eventDispatcher;

    /**
     * @var array<object>
     */
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        $this->productRepository = static::getContainer()->get('product.repository');
        $this->eventDispatcher = static::getContainer()->get('event_dispatcher');
        $this->dispatchedEvents = [];

        $this->eventDispatcher->addListener(ProductNoLongerAvailableEvent::class, function (ProductNoLongerAvailableEvent $event): void {
            $this->dispatchedEvents[] = $event;
        });

        $this->eventDispatcher->addListener(InvalidateProductCache::class, function (InvalidateProductCache $event): void {
            $this->dispatchedEvents[] = $event;
        });
    }

    public function testUpdateStockById(): void
    {
        $productId = $this->createProduct('TEST-001', 10);

        $requestData = [
            'updates' => [
                [
                    'id' => $productId,
                    'stock' => 5,
                ],
            ],
        ];

        $this->getBrowser()->request(
            'POST',
            '/api/_action/swag-advanced-sync/stock-update',
            [],
            [],
            [],
            json_encode($requestData, \JSON_THROW_ON_ERROR)
        );

        $response = $this->getBrowser()->getResponse();
        static::assertSame(Response::HTTP_OK, $response->getStatusCode());
        static::assertIsString($response->getContent());

        $responseData = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        static::assertArrayHasKey('results', $responseData);
        static::assertArrayHasKey($productId, $responseData['results']);
        static::assertSame(10, $responseData['results'][$productId]['oldStock']);
        static::assertSame(5, $responseData['results'][$productId]['newStock']);

        $product = $this->getProduct($productId);
        static::assertSame(5, $product->getStock());
    }

    public function testUpdateStockByProductNumber(): void
    {
        $productNumber = 'TEST-002';
        $productId = $this->createProduct($productNumber, 15);

        $requestData = [
            'updates' => [
                [
                    'productNumber' => $productNumber,
                    'stock' => 20,
                ],
            ],
        ];

        $this->getBrowser()->request(
            'POST',
            '/api/_action/swag-advanced-sync/stock-update',
            [],
            [],
            [],
            json_encode($requestData, \JSON_THROW_ON_ERROR)
        );

        $response = $this->getBrowser()->getResponse();
        static::assertSame(Response::HTTP_OK, $response->getStatusCode());
        static::assertIsString($response->getContent());

        $responseData = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        static::assertArrayHasKey('results', $responseData);
        static::assertArrayHasKey($productId, $responseData['results']);
        static::assertSame(15, $responseData['results'][$productId]['oldStock']);
        static::assertSame(20, $responseData['results'][$productId]['newStock']);

        $product = $this->getProduct($productId);
        static::assertSame(20, $product->getStock());
    }

    public function testThresholdExceededEvent(): void
    {
        $this->dispatchedEvents = []; // Reset events for this test

        $productId1 = $this->createProduct('TEST-012', 5);  // Below threshold
        $productId2 = $this->createProduct('TEST-013', 10); // Exactly at threshold

        $requestData = [
            'updates' => [
                [
                    'id' => $productId1,
                    'stock' => 12, // Should trigger InvalidateProductCache (was 5, now 12, threshold 10)
                    'threshold' => 10,
                ],
                [
                    'id' => $productId2,
                    'stock' => 12, // Should trigger InvalidateProductCache (was 10, now 12, threshold 10)
                    'threshold' => 10,
                ],
            ],
        ];

        $this->getBrowser()->request(
            'POST',
            '/api/_action/swag-advanced-sync/stock-update',
            [],
            [],
            [],
            json_encode($requestData, \JSON_THROW_ON_ERROR)
        );

        $response = $this->getBrowser()->getResponse();
        static::assertSame(Response::HTTP_OK, $response->getStatusCode());

        // Check that InvalidateProductCache events were dispatched for products that exceeded threshold
        $foundEvents = [];
        foreach ($this->dispatchedEvents as $event) {
            if ($event instanceof InvalidateProductCache) {
                foreach ($event->getIds() as $id) {
                    $foundEvents[] = $id;
                }
            }
        }

        static::assertContains($productId1, $foundEvents, 'InvalidateProductCache should be dispatched for product that went from below to above threshold');
        static::assertContains($productId2, $foundEvents, 'InvalidateProductCache should be dispatched for product that went from exactly at threshold to above threshold');
    }

    public function testInvalidRequestMissingStock(): void
    {
        $productId = $this->createProduct('TEST-008', 10);

        $requestData = [
            'updates' => [
                [
                    'id' => $productId,
                    // Missing stock
                ],
            ],
        ];

        $this->getBrowser()->request(
            'POST',
            '/api/_action/swag-advanced-sync/stock-update',
            [],
            [],
            [],
            json_encode($requestData, \JSON_THROW_ON_ERROR)
        );

        $response = $this->getBrowser()->getResponse();
        static::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        static::assertIsString($response->getContent());

        $responseData = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        static::assertArrayHasKey('errors', $responseData);
        static::assertCount(1, $responseData['errors']);
        static::assertStringContainsString('This field is missing', $responseData['errors'][0]['detail']);
    }

    private function createProduct(string $productNumber, int $stock): string
    {
        $productId = Uuid::randomHex();

        $data = [
            'id' => $productId,
            'productNumber' => $productNumber,
            'stock' => $stock,
            'name' => 'Test Product ' . $productNumber,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false]],
            'manufacturer' => ['name' => 'Test Manufacturer'],
            'tax' => ['name' => 'test', 'taxRate' => 15],
        ];

        $this->productRepository->create([$data], Context::createDefaultContext());

        return $productId;
    }

    private function getProduct(string $productId): ProductEntity
    {
        $criteria = new Criteria([$productId]);
        $product = $this->productRepository->search($criteria, Context::createDefaultContext())->get($productId);
        static::assertNotNull($product);

        return $product;
    }
}
