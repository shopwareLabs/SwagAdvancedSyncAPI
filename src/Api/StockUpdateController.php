<?php declare(strict_types=1);

namespace SwagAdvancedSyncAPI\Api;

use SwagAdvancedSyncAPI\Service\StockUpdateService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Optional;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Route(defaults: ['_routeScope' => ['api']])]
class StockUpdateController extends AbstractController
{
    public function __construct(
        private readonly StockUpdateService $stockUpdateService,
        private readonly DataValidator $validator
    ) {
    }

    #[Route(path: '/api/_action/swag-advanced-sync/stock-update', name: 'api.action.swag_advanced_sync.stock-update', methods: ['POST'])]
    public function updateStock(Request $request, Context $context): JsonResponse
    {
        $data = $request->toArray();

        $this->validateRequest($data);

        $results = $this->stockUpdateService->updateStock($data['updates'], $context);

        return new JsonResponse(['results' => $results]);
    }

    /**
     * @param array<mixed> $data
     */
    private function validateRequest(array $data): void
    {
        $definition = new DataValidationDefinition('swag_advanced_sync.stock_update');

        $definition->add('updates', new NotBlank(), new Type('array'), new All([
            new Collection([
                'id' => new Optional([new Type('string'), new NotBlank()]),
                'productNumber' => new Optional([new Type('string'), new NotBlank()]),
                'stock' => [new NotBlank(), new Type('int')],
                'threshold' => new Optional([new Type('int')]),
            ]),
            new Callback(function (array $value, ExecutionContextInterface $context): void {
                if (!isset($value['id']) && !isset($value['productNumber'])) {
                    $context->buildViolation('Either "id" or "productNumber" must be provided')
                        ->setCode('uniqueIdentifierNotGiven')
                        ->atPath('updates/' . $context->getPropertyPath())
                        ->addViolation();
                }
            }),
        ]));

        $this->validator->validate($data, $definition);
    }
}