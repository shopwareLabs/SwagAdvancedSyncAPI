<?php declare(strict_types=1);

namespace SwagAdvancedSyncAPI\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\DataAbstractionLayer\ProductIndexer;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Api\Sync\SyncBehavior;
use Shopware\Core\Framework\Api\Sync\SyncOperation;
use Shopware\Core\Framework\Api\Sync\SyncServiceInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Optional;
use Symfony\Component\Validator\Constraints\Required;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class PriceUpdateService
{
    public function __construct(
        private readonly SyncServiceInterface $syncService,
        private readonly EntityRepository $productRepository,
        private readonly DataValidator $validator,
        private readonly Connection $connection
    ) {
    }

    public function updatePrices(array $data, Context $context): array
    {
        $data = $this->validateRequest($data);

        $updates = $data['updates'] ?? [];
        $results = [];

        // Resolve product numbers to IDs
        $resolvedUpdates = $this->resolveProductNumbers($updates, $context);

        // Collect all product IDs for batch fetching
        $productIds = array_column($resolvedUpdates, 'id');

        // Fetch all products at once
        $currentProducts = $this->getCurrentProducts($productIds, $context);

        $syncOps = [];

        // Process each update
        foreach ($resolvedUpdates as $update) {
            $productId = $update['id'];

            // Get current product data from pre-fetched products
            if (!isset($currentProducts[$productId])) {
                continue;
            }
            $currentProduct = $currentProducts[$productId];

            // Compare and prepare sync operations
            $syncOperations = $this->prepareSyncOperations($productId, $update, $currentProduct);

            if (!empty($syncOperations)) {
                foreach ($syncOperations as $operation) {
                    $syncOps[] = $operation;
                }

                $results[$productId] = [
                    'updated' => true,
                ];
            } else {
                $results[$productId] = [
                    'updated' => false,
                    'reason' => 'No changes detected',
                ];
            }
        }

        $this->syncService->sync($syncOps, $context, new SyncBehavior(null, [
            ProductIndexer::CATEGORY_DENORMALIZER_UPDATER,
            ProductIndexer::CHILD_COUNT_UPDATER,
            ProductIndexer::INHERITANCE_UPDATER,
            ProductIndexer::MANY_TO_MANY_ID_FIELD_UPDATER,
            ProductIndexer::RATING_AVERAGE_UPDATER,
            ProductIndexer::STOCK_UPDATER,
            ProductIndexer::SEARCH_KEYWORD_UPDATER,
            ProductIndexer::STREAM_UPDATER,
            ProductIndexer::VARIANT_LISTING_UPDATER,
        ]));

        return $results;
    }

    /**
     * @param array<array{id?: string, productNumber?: string, price?: array, prices?: array}> $updates
     * @return array<array{id: string, price?: array, prices?: array}>
     */
    private function resolveProductNumbers(array $updates, Context $context): array
    {
        $productNumberToId = [];
        $resolvedUpdates = [];

        // Collect product numbers that need resolution
        foreach ($updates as $update) {
            if (isset($update['id'])) {
                // Already has ID, add directly
                $resolvedUpdates[] = $update;
            } elseif (isset($update['productNumber'])) {
                // Needs product number resolution
                $productNumberToId[$update['productNumber']] = $update;
            }
        }

        // Resolve product numbers to IDs if any exist
        if (!empty($productNumberToId)) {
            $productNumbers = array_keys($productNumberToId);
            $versionId = Uuid::fromHexToBytes($context->getVersionId());

            $productResults = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(id)) as id, product_number FROM product WHERE product_number IN (:productNumbers) AND version_id = :version',
                [
                    'productNumbers' => $productNumbers,
                    'version' => $versionId
                ],
                [
                    'productNumbers' => ArrayParameterType::STRING
                ]
            );

            foreach ($productResults as $productResult) {
                $productNumber = $productResult['product_number'];
                $productId = $productResult['id'];

                if (isset($productNumberToId[$productNumber])) {
                    $update = $productNumberToId[$productNumber];
                    $update['id'] = $productId;
                    unset($update['productNumber']); // Remove productNumber since we now have ID
                    $resolvedUpdates[] = $update;
                }
            }
        }

        return $resolvedUpdates;
    }

    private function validateRequest(array $data): array
    {
        $price = new Collection([
            'gross' => [new NotBlank(), new Type(['numeric'])],
            'net' => [new NotBlank(), new Type(['numeric'])],
            'linked' => new Optional(new Type('bool')),
            'listPrice' => [
                new Optional(
                    new Collection([
                        'allowExtraFields' => true,
                        'allowMissingFields' => false,
                        'fields' => [
                            'gross' => [new NotBlank(), new Type(['numeric'])],
                            'net' => [new NotBlank(), new Type('numeric')],
                            'linked' => new Optional(new Type('bool')),
                        ],
                    ])
                ),
            ],
            'regulationPrice' => [
                new Optional(
                    new Collection([
                        'allowExtraFields' => true,
                        'allowMissingFields' => false,
                        'fields' => [
                            'gross' => [new NotBlank(), new Type(['numeric'])],
                            'net' => [new NotBlank(), new Type('numeric')],
                            'linked' => new Optional(new Type('bool')),
                        ],
                    ])
                ),
            ],
        ]);

        $currencies = $this->connection->fetchAllKeyValue('SELECT iso_code, LOWER(HEX(id)) FROM currency');

        $definition = new DataValidationDefinition('swag_advanced_sync.price_update');
        $definition->add(
            'updates',
            new NotBlank(),
            new Type('array'),
            new All([
                new Collection([
                    'fields' => [
                        'id' => new Optional([new Type('string'), new NotBlank()]),
                        'productNumber' => new Optional([new Type('string'), new NotBlank()]),
                        'price' => new All([new Required(), $price]),
                        'prices' => new Optional(
                            new All([
                                new Collection([
                                    'allowExtraFields' => true,
                                    'allowMissingFields' => false,
                                    'fields' => [
                                        'ruleId' => new NotBlank(),
                                        'quantityStart' => new Optional([new Type('int')]),
                                        'quantityEnd' => new Optional([new Type('int')]),
                                        'price' => new All([new Required(), $price]),
                                    ],
                                ])
                            ])
                        ),
                    ],
                ]),
                new Callback(function (array $value, ExecutionContextInterface $context) use ($currencies): void {
                    if (!isset($value['id']) && !isset($value['productNumber'])) {
                        $context->buildViolation('Either "id" or "productNumber" must be provided')
                            ->setCode('uniqueIdentifierNotGiven')
                            ->atPath('updates/' . $context->getPropertyPath())
                            ->addViolation();
                    }

                    if (!isset($value['price']) && !isset($value['prices'])) {
                        $context->buildViolation('Either "price" or "prices" must be provided')
                            ->setCode('priceDataRequired')
                            ->atPath('updates/' . $context->getPropertyPath())
                            ->addViolation();
                    }

                    // Validate main price currencies
                    if (isset($value['price'])) {
                        foreach (array_keys($value['price']) as $currency) {
                            if (!isset($currencies[$currency])) {
                                $context
                                    ->buildViolation('The currency with code %code% cannot be found')
                                    ->setParameter('%code%', $currency)
                                    ->atPath('updates/' . $context->getPropertyPath() . '.price.' . $currency)
                                    ->addViolation();
                            }
                        }
                    }

                    // Validate advanced prices currencies
                    if (isset($value['prices'])) {
                        foreach ($value['prices'] as $index => $advancedPrice) {
                            if (isset($advancedPrice['price'])) {
                                foreach (array_keys($advancedPrice['price']) as $currency) {
                                    if (!isset($currencies[$currency])) {
                                        $context
                                            ->buildViolation('The currency with code %code% cannot be found')
                                            ->setParameter('%code%', $currency)
                                            ->atPath('updates/' . $context->getPropertyPath() . '.prices[' . $index . '].price.' . $currency)
                                            ->addViolation();
                                    }
                                }
                            }
                        }
                    }
                }),
            ])
        );

        $this->validator->validate($data, $definition);

        foreach ($data['updates'] as &$update) {
            // Process main price
            if (isset($update['price'])) {
                $update['price'] = $this->processPriceData($update['price'], $currencies);
            }

            // Process advanced prices
            if (isset($update['prices'])) {
                foreach ($update['prices'] as &$advancedPrice) {
                    if (!isset($advancedPrice['quantityStart'])) {
                        $advancedPrice['quantityStart'] = 1;
                    }

                    $advancedPrice['price'] = $this->processPriceData($advancedPrice['price'], $currencies);
                }
                unset($advancedPrice);
            }
        }

        unset($update);

        return $data;
    }

    private function processPriceData(array $priceData, array $currencies): array
    {
        $processedPrices = [];

        foreach ($priceData as $key => $price) {
            $newPrice = $price + ['currencyId' => $currencies[$key]];

            if (!isset($newPrice['linked'])) {
                $newPrice['linked'] = false;
            }

            // Process nested price objects (listPrice, regulationPrice)
            if (isset($newPrice['listPrice'])) {
                if (!isset($newPrice['listPrice']['linked'])) {
                    $newPrice['listPrice']['linked'] = false;
                }
            }

            if (isset($newPrice['regulationPrice'])) {
                if (!isset($newPrice['regulationPrice']['linked'])) {
                    $newPrice['regulationPrice']['linked'] = false;
                }
            }

            $processedPrices[$currencies[$key]] = $newPrice;
        }

        return $processedPrices;
    }

    /**
     * @param array<string> $productIds
     * @return array<string, array{id: string, price: array, prices: array}>
     */
    private function getCurrentProducts(array $productIds, Context $context): array
    {
        if (empty($productIds)) {
            return [];
        }

        $criteria = new Criteria($productIds);
        $criteria->addFields(['price', 'prices']);

        $products = $this->productRepository->search($criteria, $context);
        $result = [];

        foreach ($products as $product) {
            $productId = $product->getId();

            // Get current advanced prices
            $prices = [];
            if ($product->get('prices')) {
                foreach ($product->get('prices') as $price) {
                    $prices[] = [
                        'id' => $price->getId(),
                        'ruleId' => $price->getRuleId(),
                        'quantityStart' => $price->getQuantityStart(),
                        'quantityEnd' => $price->getQuantityEnd(),
                        'price' => $price->getPrice()->fmap($this->mapPriceToArray(...)),
                    ];
                }
            }

            $result[$productId] = [
                'id' => $productId,
                'price' => $product->get('price')->fmap($this->mapPriceToArray(...)),
                'prices' => $prices,
            ];
        }

        return $result;
    }

    private function mapPriceToArray(Price $price, bool $omitCurrencyId = false): array
    {
        $list = [
            'net' => $price->getNet(),
            'gross' => $price->getGross(),
            'linked' => $price->getLinked(),
        ];

        if (!$omitCurrencyId) {
            $list['currencyId'] = $price->getCurrencyId();
        }

        if ($price->getListPrice()) {
            $list['listPrice'] = $this->mapPriceToArray($price->getListPrice(), true);
        }

        if ($price->getRegulationPrice()) {
            $list['regulationPrice'] = $this->mapPriceToArray($price->getRegulationPrice(), true);
        }

        return $list;
    }

    /**
     * @return SyncOperation[]
     */
    private function prepareSyncOperations(string $productId, array $update, array $currentProduct): array
    {
        $operations = [];

        // Handle main price update
        if (isset($update['price'])) {
            $priceChanged = $this->arrayDeepDiff(['price' => $update['price']], ['price' => $currentProduct['price']]) !== [];
            if ($priceChanged) {
                $operations[] = new SyncOperation(
                    'product-price-update',
                    'product',
                    SyncOperation::ACTION_UPSERT,
                    [
                        [
                            'id' => $productId,
                            'price' => $update['price'],
                        ],
                    ]
                );
            }
        }

        // Handle advanced prices (selective update)
        if (isset($update['prices'])) {
            $priceOperations = $this->getAdvancedPriceOperations(
                $update['prices'],
                $currentProduct['prices'] ?? [],
                $productId
            );
            $operations = array_merge($operations, $priceOperations);
        }

        return $operations;
    }

    /**
     * @return SyncOperation[]
     */
    private function getAdvancedPriceOperations(array $newAdvancedPrices, array $currentAdvancedPrices, string $productId): array
    {
        $operations = [];

        // Create indexes for faster lookup
        $currentPricesIndex = [];
        foreach ($currentAdvancedPrices as $currentPrice) {
            $key = $currentPrice['ruleId'] . '_' . $currentPrice['quantityStart'] . '_' . ($currentPrice['quantityEnd'] ?? 'null');
            $currentPricesIndex[$key] = $currentPrice;
        }

        $newPricesIndex = [];
        foreach ($newAdvancedPrices as $newPrice) {
            $key = $newPrice['ruleId'] . '_' . $newPrice['quantityStart'] . '_' . ($newPrice['quantityEnd'] ?? 'null');
            $newPricesIndex[$key] = $newPrice;
        }

        // Find prices to delete (exist in current but not in new)
        $deleteIds = [];
        foreach ($currentPricesIndex as $key => $currentPrice) {
            if (!isset($newPricesIndex[$key])) {
                $deleteIds[] = ['id' => $currentPrice['id']];
            }
        }

        if (!empty($deleteIds)) {
            $operations[] = new SyncOperation(
                'product-price-delete',
                'product_price',
                SyncOperation::ACTION_DELETE,
                $deleteIds
            );
        }

        // Find prices to create/update
        $upsertPrices = [];
        foreach ($newPricesIndex as $key => $newPrice) {
            $currentPrice = $currentPricesIndex[$key] ?? null;

            if (!$currentPrice) {
                // New price - create
                $upsertPrices[] = [
                    'productId' => $productId,
                    'ruleId' => $newPrice['ruleId'],
                    'quantityStart' => $newPrice['quantityStart'],
                    'quantityEnd' => $newPrice['quantityEnd'] ?? null,
                    'price' => $newPrice['price']
                ];
            } else {
                // Check if price data has changed
                $newPriceComparable = [
                    'ruleId' => $newPrice['ruleId'],
                    'quantityStart' => $newPrice['quantityStart'],
                    'quantityEnd' => $newPrice['quantityEnd'] ?? null,
                    'price' => $newPrice['price']
                ];

                $currentPriceComparable = [
                    'ruleId' => $currentPrice['ruleId'],
                    'quantityStart' => $currentPrice['quantityStart'],
                    'quantityEnd' => $currentPrice['quantityEnd'],
                    'price' => $currentPrice['price']
                ];

                if ($this->arrayDeepDiff($newPriceComparable, $currentPriceComparable) !== []) {
                    // Price changed - update
                    $upsertPrices[] = [
                        'id' => $currentPrice['id'],
                        'productId' => $productId,
                        'ruleId' => $newPrice['ruleId'],
                        'quantityStart' => $newPrice['quantityStart'],
                        'quantityEnd' => $newPrice['quantityEnd'] ?? null,
                        'price' => $newPrice['price']
                    ];
                }
            }
        }

        if (!empty($upsertPrices)) {
            $operations[] = new SyncOperation(
                'product-price-upsert',
                'product_price',
                SyncOperation::ACTION_UPSERT,
                $upsertPrices
            );
        }

        return $operations;
    }

    private function arrayDeepDiff(array $array1, array $array2): array
    {
        $diff = [];

        // Check all keys from both arrays
        $allKeys = array_unique(array_merge(array_keys($array1), array_keys($array2)));

        foreach ($allKeys as $key) {
            if (!array_key_exists($key, $array1)) {
                // Key only exists in array2
                $diff[$key] = $array2[$key];
            } elseif (!array_key_exists($key, $array2)) {
                // Key only exists in array1
                $diff[$key] = $array1[$key];
            } elseif (is_array($array1[$key]) && is_array($array2[$key])) {
                // Both are arrays, recurse
                $nestedDiff = $this->arrayDeepDiff($array1[$key], $array2[$key]);
                if (!empty($nestedDiff)) {
                    $diff[$key] = $nestedDiff;
                }
            } elseif ($array1[$key] != $array2[$key]) {
                // Values are different
                $diff[$key] = $array2[$key]; // or $array1[$key] depending on what you want
            }
        }

        return $diff;
    }
}
