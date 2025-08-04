<?php declare(strict_types=1);

namespace SwagAdvancedSyncAPI\Tests\Unit\Api;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use SwagAdvancedSyncAPI\Api\StockUpdateController;
use SwagAdvancedSyncAPI\Service\StockUpdateService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @internal
 * Unit test for StockUpdateController - tests controller logic without dependencies
 */
class StockUpdateControllerTest extends TestCase
{
    private MockObject|StockUpdateService $stockUpdateService;
    private MockObject|DataValidator $validator;
    private StockUpdateController $controller;

    protected function setUp(): void
    {
        $this->stockUpdateService = $this->createMock(StockUpdateService::class);
        $this->validator = $this->createMock(DataValidator::class);
        $this->controller = new StockUpdateController($this->stockUpdateService, $this->validator);
    }

    public function testUpdateStockCallsServiceAndReturnsJsonResponse(): void
    {
        // Arrange
        $requestData = [
            'updates' => [
                [
                    'id' => 'product-id-123',
                    'stock' => 50,
                    'threshold' => 10,
                ]
            ]
        ];

        $expectedResults = [
            'product-id-123' => [
                'updated' => true,
                'oldStock' => 10,
                'newStock' => 50,
                'available' => true,
            ]
        ];

        $request = new Request([], [], [], [], [], [], json_encode($requestData));
        $context = Context::createDefaultContext();

        // Expect validator to be called
        $this->validator
            ->expects(static::once())
            ->method('validate')
            ->with($requestData, static::isInstanceOf(DataValidationDefinition::class));

        // Expect service to be called with correct parameters
        $this->stockUpdateService
            ->expects(static::once())
            ->method('updateStock')
            ->with($requestData['updates'], $context)
            ->willReturn($expectedResults);

        // Act
        $response = $this->controller->updateStock($request, $context);

        // Assert
        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        static::assertArrayHasKey('results', $responseData);
        static::assertEquals($expectedResults, $responseData['results']);
    }

    public function testUpdateStockHandlesEmptyUpdatesArray(): void
    {
        // Arrange
        $requestData = ['updates' => []];
        $expectedResults = [];

        $request = new Request([], [], [], [], [], [], json_encode($requestData));
        $context = Context::createDefaultContext();

        $this->validator
            ->expects(static::once())
            ->method('validate');

        $this->stockUpdateService
            ->expects(static::once())
            ->method('updateStock')
            ->with($requestData['updates'], $context)
            ->willReturn($expectedResults);

        // Act
        $response = $this->controller->updateStock($request, $context);

        // Assert
        static::assertInstanceOf(JsonResponse::class, $response);
        $responseData = json_decode($response->getContent(), true);
        static::assertArrayHasKey('results', $responseData);
        static::assertEquals($expectedResults, $responseData['results']);
    }

    public function testUpdateStockHandlesMultipleProducts(): void
    {
        // Arrange
        $requestData = [
            'updates' => [
                [
                    'id' => 'product-1',
                    'stock' => 100,
                ],
                [
                    'productNumber' => 'PROD-002',
                    'stock' => 25,
                    'threshold' => 5,
                ]
            ]
        ];

        $expectedResults = [
            'product-1' => ['updated' => true, 'oldStock' => 50, 'newStock' => 100],
            'product-2-id' => ['updated' => true, 'oldStock' => 15, 'newStock' => 25]
        ];

        $request = new Request([], [], [], [], [], [], json_encode($requestData));
        $context = Context::createDefaultContext();

        $this->validator
            ->expects(static::once())
            ->method('validate');

        $this->stockUpdateService
            ->expects(static::once())
            ->method('updateStock')
            ->with($requestData['updates'], $context)
            ->willReturn($expectedResults);

        // Act
        $response = $this->controller->updateStock($request, $context);

        // Assert
        static::assertInstanceOf(JsonResponse::class, $response);
        $responseData = json_decode($response->getContent(), true);
        static::assertEquals($expectedResults, $responseData['results']);
    }

    public function testValidationErrorThrowsException(): void
    {
        // Arrange
        $requestData = [
            'updates' => [
                [
                    'id' => 'product-id-123',
                    // Missing required 'stock' field
                ]
            ]
        ];

        $request = new Request([], [], [], [], [], [], json_encode($requestData));
        $context = Context::createDefaultContext();

        $violations = new ConstraintViolationList();
        $exception = new ConstraintViolationException($violations, $requestData);

        $this->validator
            ->expects(static::once())
            ->method('validate')
            ->willThrowException($exception);

        $this->stockUpdateService
            ->expects(static::never())
            ->method('updateStock');

        // Act & Assert
        $this->expectException(ConstraintViolationException::class);
        $this->controller->updateStock($request, $context);
    }

    public function testControllerPassesCorrectDataToValidator(): void
    {
        // Arrange
        $requestData = [
            'updates' => [
                ['id' => 'test', 'stock' => 10]
            ]
        ];
        $request = new Request([], [], [], [], [], [], json_encode($requestData));
        $context = Context::createDefaultContext();

        // Mock validator to verify the exact data and definition
        $this->validator
            ->expects(static::once())
            ->method('validate')
            ->with(
                static::equalTo($requestData),
                static::callback(function (DataValidationDefinition $definition) {
                    return $definition->getName() === 'swag_advanced_sync.stock_update';
                })
            );

        $this->stockUpdateService
            ->expects(static::once())
            ->method('updateStock')
            ->willReturn([]);

        // Act
        $this->controller->updateStock($request, $context);
    }

    public function testControllerPassesContextCorrectlyToService(): void
    {
        // Arrange
        $requestData = ['updates' => []];
        $request = new Request([], [], [], [], [], [], json_encode($requestData));
        $context = Context::createDefaultContext();

        $this->validator
            ->expects(static::once())
            ->method('validate');

        // Mock service to verify the exact context instance is passed
        $this->stockUpdateService
            ->expects(static::once())
            ->method('updateStock')
            ->with(
                static::equalTo($requestData['updates']),
                static::identicalTo($context)
            )
            ->willReturn([]);

        // Act
        $this->controller->updateStock($request, $context);
    }
}