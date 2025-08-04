<?php declare(strict_types=1);

namespace SwagAdvancedSyncAPI\Tests\Unit\Api;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use SwagAdvancedSyncAPI\Api\PriceUpdateController;
use SwagAdvancedSyncAPI\Service\PriceUpdateService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 * Unit test for PriceUpdateController - tests controller logic without dependencies
 */
class PriceUpdateControllerTest extends TestCase
{
    private MockObject|PriceUpdateService $priceUpdateService;
    private PriceUpdateController $controller;

    protected function setUp(): void
    {
        $this->priceUpdateService = $this->createMock(PriceUpdateService::class);
        $this->controller = new PriceUpdateController($this->priceUpdateService);
    }

    public function testUpdatePricesCallsServiceAndReturnsJsonResponse(): void
    {
        // Arrange
        $requestData = [
            'updates' => [
                [
                    'id' => 'product-id-123',
                    'price' => [
                        'EUR' => [
                            'gross' => 150.00,
                            'net' => 126.05,
                        ],
                    ],
                ]
            ]
        ];

        $expectedResults = [
            'product-id-123' => [
                'updated' => true,
            ]
        ];

        $request = new Request([], [], [], [], [], [], json_encode($requestData));
        $context = Context::createDefaultContext();

        // Expect service to be called with correct parameters
        $this->priceUpdateService
            ->expects(static::once())
            ->method('updatePrices')
            ->with($requestData, $context)
            ->willReturn($expectedResults);

        // Act
        $response = $this->controller->updatePrices($request, $context);

        // Assert
        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        static::assertArrayHasKey('results', $responseData);
        static::assertEquals($expectedResults, $responseData['results']);
    }

    public function testUpdatePricesHandlesEmptyUpdatesArray(): void
    {
        // Arrange
        $requestData = ['updates' => []];
        $expectedResults = [];

        $request = new Request([], [], [], [], [], [], json_encode($requestData));
        $context = Context::createDefaultContext();

        $this->priceUpdateService
            ->expects(static::once())
            ->method('updatePrices')
            ->with($requestData, $context)
            ->willReturn($expectedResults);

        // Act
        $response = $this->controller->updatePrices($request, $context);

        // Assert
        static::assertInstanceOf(JsonResponse::class, $response);
        $responseData = json_decode($response->getContent(), true);
        static::assertArrayHasKey('results', $responseData);
        static::assertEquals($expectedResults, $responseData['results']);
    }

    public function testUpdatePricesHandlesMultipleProducts(): void
    {
        // Arrange
        $requestData = [
            'updates' => [
                [
                    'id' => 'product-1',
                    'price' => ['EUR' => ['gross' => 100.00, 'net' => 84.03]],
                ],
                [
                    'id' => 'product-2',
                    'prices' => [
                        [
                            'ruleId' => 'rule-1',
                            'quantityStart' => 1,
                            'price' => ['EUR' => ['gross' => 95.00, 'net' => 79.83]],
                        ],
                    ],
                ]
            ]
        ];

        $expectedResults = [
            'product-1' => ['updated' => true],
            'product-2' => ['updated' => true]
        ];

        $request = new Request([], [], [], [], [], [], json_encode($requestData));
        $context = Context::createDefaultContext();

        $this->priceUpdateService
            ->expects(static::once())
            ->method('updatePrices')
            ->with($requestData, $context)
            ->willReturn($expectedResults);

        // Act
        $response = $this->controller->updatePrices($request, $context);

        // Assert
        static::assertInstanceOf(JsonResponse::class, $response);
        $responseData = json_decode($response->getContent(), true);
        static::assertEquals($expectedResults, $responseData['results']);
    }

    public function testUpdatePricesHandlesServiceReturningNoChanges(): void
    {
        // Arrange
        $requestData = [
            'updates' => [
                [
                    'id' => 'product-id-123',
                    'price' => ['EUR' => ['gross' => 100.00, 'net' => 84.03]],
                ]
            ]
        ];

        $expectedResults = [
            'product-id-123' => [
                'updated' => false,
                'reason' => 'No changes detected'
            ]
        ];

        $request = new Request([], [], [], [], [], [], json_encode($requestData));
        $context = Context::createDefaultContext();

        $this->priceUpdateService
            ->expects(static::once())
            ->method('updatePrices')
            ->with($requestData, $context)
            ->willReturn($expectedResults);

        // Act
        $response = $this->controller->updatePrices($request, $context);

        // Assert
        static::assertInstanceOf(JsonResponse::class, $response);
        $responseData = json_decode($response->getContent(), true);
        static::assertEquals($expectedResults, $responseData['results']);
    }

    public function testControllerPassesContextCorrectlyToService(): void
    {
        // Arrange
        $requestData = ['updates' => []];
        $request = new Request([], [], [], [], [], [], json_encode($requestData));
        $context = Context::createDefaultContext();

        // Mock service to verify the exact context instance is passed
        $this->priceUpdateService
            ->expects(static::once())
            ->method('updatePrices')
            ->with(
                static::equalTo($requestData),
                static::identicalTo($context)
            )
            ->willReturn([]);

        // Act
        $this->controller->updatePrices($request, $context);
    }
}