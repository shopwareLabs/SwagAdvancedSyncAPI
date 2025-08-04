<?php declare(strict_types=1);

namespace SwagAdvancedSyncAPI\Tests\Integration\Service;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\AdminFunctionalTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use SwagAdvancedSyncAPI\Service\PriceUpdateService;

/**
 * @internal
 */
class PriceUpdateServiceTest extends TestCase
{
    use AdminFunctionalTestBehaviour;

    /**
     * @var EntityRepository<ProductCollection>
     */
    private EntityRepository $productRepository;
    private EntityRepository $productPriceRepository;
    private EntityRepository $taxRepository;
    private EntityRepository $ruleRepository;
    private PriceUpdateService $priceUpdateService;
    private string $productId;
    private string $taxId;
    private string $ruleId;
    private string $ruleId2;

    protected function setUp(): void
    {
        $this->productRepository = static::getContainer()->get('product.repository');
        $this->productPriceRepository = static::getContainer()->get('product_price.repository');
        $this->taxRepository = static::getContainer()->get('tax.repository');
        $this->ruleRepository = static::getContainer()->get('rule.repository');
        $this->priceUpdateService = static::getContainer()->get('SwagAdvancedSyncAPI\Service\PriceUpdateService');

        $this->createTestData();
    }

    public function testUpdateProductPriceSuccess(): void
    {
        $updateData = [
            'updates' => [
                [
                    'id' => $this->productId,
                    'price' => [
                        'EUR' => [
                            'gross' => 150.00,
                            'net' => 126.05,
                            'listPrice' => [
                                'gross' => 200.00,
                                'net' => 168.07,
                            ],
                            'regulationPrice' => [
                                'gross' => 180.00,
                                'net' => 151.26,
                            ],
                        ],
                    ],
                ]
            ]
        ];

        $results = $this->priceUpdateService->updatePrices($updateData, Context::createDefaultContext());

        static::assertArrayHasKey($this->productId, $results);
        static::assertTrue($results[$this->productId]['updated']);

        // Verify price was actually updated
        $product = $this->productRepository->search(new Criteria([$this->productId]), Context::createDefaultContext())->first();
        $price = $product->getPrice()->first();
        static::assertEquals(150.00, $price->getGross());
        static::assertEquals(126.05, $price->getNet());
        static::assertEquals(200.00, $price->getListPrice()->getGross());
        static::assertEquals(180.00, $price->getRegulationPrice()->getGross());
    }

    public function testCreateAdvancedPrices(): void
    {
        $updateData = [
            'updates' => [
                [
                    'id' => $this->productId,
                    'prices' => [
                        [
                            'ruleId' => $this->ruleId,
                            'quantityStart' => 1,
                            'quantityEnd' => 10,
                            'price' => [
                                'EUR' => [
                                    'gross' => 95.00,
                                    'net' => 79.83,
                                ],
                            ],
                        ],
                        [
                            'ruleId' => $this->ruleId,
                            'quantityStart' => 11,
                            'price' => [
                                'EUR' => [
                                    'gross' => 90.00,
                                    'net' => 75.63,
                                ],
                            ],
                        ],
                    ],
                ]
            ]
        ];

        $results = $this->priceUpdateService->updatePrices($updateData, Context::createDefaultContext());

        static::assertTrue($results[$this->productId]['updated']);

        // Verify advanced prices were created
        $criteria = new Criteria([$this->productId]);
        $criteria->addAssociation('prices');
        $product = $this->productRepository->search($criteria, Context::createDefaultContext())->first();

        static::assertCount(2, $product->getPrices());

        $prices = $product->getPrices()->getElements();
        usort($prices, fn($a, $b) => $a->getQuantityStart() <=> $b->getQuantityStart());

        static::assertEquals(1, $prices[0]->getQuantityStart());
        static::assertEquals(10, $prices[0]->getQuantityEnd());
        static::assertEquals(95.00, $prices[0]->getPrice()->first()->getGross());

        static::assertEquals(11, $prices[1]->getQuantityStart());
        static::assertNull($prices[1]->getQuantityEnd());
        static::assertEquals(90.00, $prices[1]->getPrice()->first()->getGross());
    }

    public function testSelectiveUpdateAdvancedPrices(): void
    {
        // First, create initial advanced prices
        $this->productRepository->update([
            [
                'id' => $this->productId,
                'prices' => [
                    [
                        'ruleId' => $this->ruleId,
                        'quantityStart' => 1,
                        'quantityEnd' => 10,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 95.00, 'net' => 79.83, 'linked' => false]],
                    ],
                    [
                        'ruleId' => $this->ruleId,
                        'quantityStart' => 11,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 90.00, 'net' => 75.63, 'linked' => false]],
                    ],
                    [
                        'ruleId' => $this->ruleId2,
                        'quantityStart' => 5,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 85.00, 'net' => 71.43, 'linked' => false]],
                    ]
                ],
            ]
        ], Context::createDefaultContext());

        // Now update: modify first price, keep second unchanged, delete third, add new fourth
        $updateData = [
            'updates' => [
                [
                    'id' => $this->productId,
                    'prices' => [
                        [
                            'ruleId' => $this->ruleId,
                            'quantityStart' => 1,
                            'quantityEnd' => 10,
                            'price' => [
                                'EUR' => [
                                    'gross' => 97.00, // Changed from 95.00
                                    'net' => 81.51,
                                ],
                            ],
                        ],
                        [
                            'ruleId' => $this->ruleId,
                            'quantityStart' => 11,
                            'price' => [
                                'EUR' => [
                                    'gross' => 90.00, // Unchanged
                                    'net' => 75.63,
                                ],
                            ],
                        ],
                        // Third price (rule2, qty 5) is deleted by omission
                        [
                            'ruleId' => $this->ruleId,
                            'quantityStart' => 20, // New price
                            'price' => [
                                'EUR' => [
                                    'gross' => 80.00,
                                    'net' => 67.23,
                                ],
                            ],
                        ],
                    ],
                ]
            ]
        ];

        $results = $this->priceUpdateService->updatePrices($updateData, Context::createDefaultContext());

        static::assertTrue($results[$this->productId]['updated']);

        // Verify selective changes
        $criteria = new Criteria([$this->productId]);
        $criteria->addAssociation('prices');
        $product = $this->productRepository->search($criteria, Context::createDefaultContext())->first();

        static::assertCount(3, $product->getPrices()); // One deleted, one added = same count

        $prices = $product->getPrices()->getElements();
        usort($prices, fn($a, $b) => $a->getQuantityStart() <=> $b->getQuantityStart());

        // First price should be updated
        static::assertEquals(1, $prices[0]->getQuantityStart());
        static::assertEquals(10, $prices[0]->getQuantityEnd());
        static::assertEquals(97.00, $prices[0]->getPrice()->first()->getGross()); // Updated

        // Second price should be unchanged
        static::assertEquals(11, $prices[1]->getQuantityStart());
        static::assertEquals(90.00, $prices[1]->getPrice()->first()->getGross()); // Unchanged

        // Third price should be the new one
        static::assertEquals(20, $prices[2]->getQuantityStart());
        static::assertEquals(80.00, $prices[2]->getPrice()->first()->getGross()); // New
    }

    public function testDeleteAllAdvancedPrices(): void
    {
        // First, create some advanced prices
        $this->productRepository->update([
            [
                'id' => $this->productId,
                'prices' => [
                    [
                        'ruleId' => $this->ruleId,
                        'quantityStart' => 1,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 90.00, 'net' => 75.63, 'linked' => false]],
                    ],
                    [
                        'ruleId' => $this->ruleId2,
                        'quantityStart' => 5,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 85.00, 'net' => 71.43, 'linked' => false]],
                    ]
                ],
            ]
        ], Context::createDefaultContext());

        // Delete all by sending empty array
        $updateData = [
            'updates' => [
                [
                    'id' => $this->productId,
                    'prices' => [],
                ]
            ]
        ];

        $results = $this->priceUpdateService->updatePrices($updateData, Context::createDefaultContext());

        // Verify all advanced prices were deleted
        $criteria = new Criteria([$this->productId]);
        $criteria->addAssociation('prices');
        $product = $this->productRepository->search($criteria, Context::createDefaultContext())->first();

        static::assertEmpty($product->getPrices());
    }

    public function testNoUpdateWhenAdvancedPricesUnchanged(): void
    {
        // First, create advanced prices
        $this->productRepository->update([
            [
                'id' => $this->productId,
                'prices' => [
                    [
                        'ruleId' => $this->ruleId,
                        'quantityStart' => 1,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 90.00, 'net' => 75.63, 'linked' => false]],
                    ]
                ],
            ]
        ], Context::createDefaultContext());

        // Send same prices again
        $updateData = [
            'updates' => [
                [
                    'id' => $this->productId,
                    'prices' => [
                        [
                            'ruleId' => $this->ruleId,
                            'quantityStart' => 1,
                            'price' => [
                                'EUR' => [
                                    'gross' => 90.00,
                                    'net' => 75.63,
                                ],
                            ],
                        ],
                    ],
                ]
            ]
        ];

        $results = $this->priceUpdateService->updatePrices($updateData, Context::createDefaultContext());

        static::assertFalse($results[$this->productId]['updated']);
        static::assertEquals('No changes detected', $results[$this->productId]['reason']);
    }

    public function testUpdateBothPriceAndAdvancedPrices(): void
    {
        $updateData = [
            'updates' => [
                [
                    'id' => $this->productId,
                    'price' => [
                        'EUR' => [
                            'gross' => 200.00,
                            'net' => 168.07,
                        ],
                    ],
                    'prices' => [
                        [
                            'ruleId' => $this->ruleId,
                            'quantityStart' => 5,
                            'price' => [
                                'EUR' => [
                                    'gross' => 180.00,
                                    'net' => 151.26,
                                ],
                            ],
                        ],
                    ],
                ]
            ]
        ];

        $results = $this->priceUpdateService->updatePrices($updateData, Context::createDefaultContext());

        static::assertTrue($results[$this->productId]['updated']);

        // Verify both prices were updated
        $criteria = new Criteria([$this->productId]);
        $criteria->addAssociation('prices');
        $product = $this->productRepository->search($criteria, Context::createDefaultContext())->first();

        // Check main price
        $mainPrice = $product->getPrice()->first();
        static::assertEquals(200.00, $mainPrice->getGross());

        // Check advanced price
        static::assertCount(1, $product->getPrices());
        $advancedPrice = $product->getPrices()->first();
        static::assertEquals(5, $advancedPrice->getQuantityStart());
        static::assertEquals(180.00, $advancedPrice->getPrice()->first()->getGross());
    }

    public function testAdvancedPriceWithMultipleRules(): void
    {
        $updateData = [
            'updates' => [
                [
                    'id' => $this->productId,
                    'prices' => [
                        [
                            'ruleId' => $this->ruleId,
                            'quantityStart' => 1,
                            'price' => [
                                'EUR' => [
                                    'gross' => 95.00,
                                    'net' => 79.83,
                                ],
                            ],
                        ],
                        [
                            'ruleId' => $this->ruleId2,
                            'quantityStart' => 1,
                            'price' => [
                                'EUR' => [
                                    'gross' => 92.00,
                                    'net' => 77.31,
                                ],
                            ],
                        ],
                    ],
                ]
            ]
        ];

        $results = $this->priceUpdateService->updatePrices($updateData, Context::createDefaultContext());

        // Verify both rules created prices
        $criteria = new Criteria([$this->productId]);
        $criteria->addAssociation('prices');
        $product = $this->productRepository->search($criteria, Context::createDefaultContext())->first();

        static::assertCount(2, $product->getPrices());

        $prices = $product->getPrices()->getElements();
        $ruleIds = array_map(fn($price) => $price->getRuleId(), $prices);

        static::assertContains($this->ruleId, $ruleIds);
        static::assertContains($this->ruleId2, $ruleIds);
    }

    public function testUpdatePriceByProductNumber(): void
    {
        // Get the product number from the created product
        $product = $this->productRepository->search(new Criteria([$this->productId]), Context::createDefaultContext())->first();
        $productNumber = $product->getProductNumber();

        $updateData = [
            'updates' => [
                [
                    'productNumber' => $productNumber,
                    'price' => [
                        'EUR' => [
                            'gross' => 175.00,
                            'net' => 147.06,
                        ],
                    ],
                ]
            ]
        ];

        $results = $this->priceUpdateService->updatePrices($updateData, Context::createDefaultContext());

        static::assertArrayHasKey($this->productId, $results);
        static::assertTrue($results[$this->productId]['updated']);

        // Verify price was actually updated
        $product = $this->productRepository->search(new Criteria([$this->productId]), Context::createDefaultContext())->first();
        $price = $product->getPrice()->first();
        static::assertEquals(175.00, $price->getGross());
        static::assertEquals(147.06, $price->getNet());
    }

    public function testBatchUpdateMultipleProducts(): void
    {
        // Create additional test products
        $product2Id = Uuid::randomHex();
        $product3Id = Uuid::randomHex();
        
        $this->productRepository->create([
            [
                'id' => $product2Id,
                'productNumber' => 'TEST-PRICE-2-' . Uuid::randomHex(),
                'name' => 'Test Product 2 for Price Updates',
                'taxId' => $this->taxId,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 120.00, 'net' => 100.84, 'linked' => false]],
                'stock' => 15,
            ],
            [
                'id' => $product3Id,
                'productNumber' => 'TEST-PRICE-3-' . Uuid::randomHex(),
                'name' => 'Test Product 3 for Price Updates',
                'taxId' => $this->taxId,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 80.00, 'net' => 67.23, 'linked' => false]],
                'stock' => 5,
            ]
        ], Context::createDefaultContext());

        // Update all three products in one batch
        $updateData = [
            'updates' => [
                [
                    'id' => $this->productId,
                    'price' => [
                        'EUR' => [
                            'gross' => 150.00,
                            'net' => 126.05,
                        ],
                    ],
                ],
                [
                    'id' => $product2Id,
                    'price' => [
                        'EUR' => [
                            'gross' => 140.00,
                            'net' => 117.65,
                        ],
                    ],
                ],
                [
                    'id' => $product3Id,
                    'price' => [
                        'EUR' => [
                            'gross' => 90.00,
                            'net' => 75.63,
                        ],
                    ],
                ]
            ]
        ];

        $results = $this->priceUpdateService->updatePrices($updateData, Context::createDefaultContext());

        // Verify all products were updated
        static::assertCount(3, $results);
        static::assertTrue($results[$this->productId]['updated']);
        static::assertTrue($results[$product2Id]['updated']);
        static::assertTrue($results[$product3Id]['updated']);

        // Verify prices were actually updated
        $products = $this->productRepository->search(
            new Criteria([$this->productId, $product2Id, $product3Id]), 
            Context::createDefaultContext()
        );

        foreach ($products as $product) {
            $price = $product->getPrice()->first();
            
            if ($product->getId() === $this->productId) {
                static::assertEquals(150.00, $price->getGross());
            } elseif ($product->getId() === $product2Id) {
                static::assertEquals(140.00, $price->getGross());
            } elseif ($product->getId() === $product3Id) {
                static::assertEquals(90.00, $price->getGross());
            }
        }
    }

    private function createTestData(): void
    {
        $this->productId = Uuid::randomHex();
        $this->taxId = Uuid::randomHex();
        $this->ruleId = Uuid::randomHex();
        $this->ruleId2 = Uuid::randomHex();

        // Create tax
        $this->taxRepository->create([
            [
                'id' => $this->taxId,
                'name' => 'Test Tax',
                'taxRate' => 19.0,
            ]
        ], Context::createDefaultContext());

        // Create rules
        $this->ruleRepository->create([
            [
                'id' => $this->ruleId,
                'name' => 'Test Rule 1',
                'priority' => 1,
            ],
            [
                'id' => $this->ruleId2,
                'name' => 'Test Rule 2',
                'priority' => 2,
            ]
        ], Context::createDefaultContext());

        // Create product
        $this->productRepository->create([
            [
                'id' => $this->productId,
                'productNumber' => 'TEST-PRICE-' . Uuid::randomHex(),
                'name' => 'Test Product for Price Updates',
                'taxId' => $this->taxId,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 100.00, 'net' => 84.03, 'linked' => false]],
                'stock' => 10,
            ]
        ], Context::createDefaultContext());
    }
}
