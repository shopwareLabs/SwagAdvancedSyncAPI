<?php declare(strict_types=1);

namespace SwagAdvancedSyncAPI\Api;

use SwagAdvancedSyncAPI\Service\PriceUpdateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Shopware\Core\Framework\Context;

#[Route(defaults: ['_routeScope' => ['api']])]
class PriceUpdateController extends AbstractController
{
    public function __construct(
        private readonly PriceUpdateService $priceUpdateService
    ) {
    }

    #[Route(path: '/api/_action/swag-advanced-sync/price-update', name: 'api.action.swag-advanced-sync.price-update', methods: ['POST'])]
    public function updatePrices(Request $request, Context $context): JsonResponse
    {
        $data = $request->toArray();

        $results = $this->priceUpdateService->updatePrices($data, $context);

        return new JsonResponse(['results' => $results]);
    }
}