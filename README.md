# SwagAdvancedSyncAPI

An advanced synchronization API plugin for Shopware that provides enhanced stock management capabilities.

## Features

- **Advanced Stock Update API**: Update product stock with intelligent event dispatching
- **Product Resolution**: Update products by ID or product number
- **Event Integration**: Automatic cache invalidation and stock status events
- **Threshold Support**: Trigger cache invalidation when stock exceeds custom thresholds
- **Symfony Validation**: Comprehensive input validation with detailed error responses

## API Endpoints

### Stock Update

**POST** `/api/_action/swag-advanced-sync/stock-update`

Update stock levels for multiple products with advanced event handling.

#### Request Body

```json
{
  "updates": [
    {
      "id": "product-uuid",
      "stock": 15,
      "threshold": 10
    },
    {
      "productNumber": "PROD-001", 
      "stock": 25,
      "threshold": 20
    }
  ]
}
```

#### Parameters

- `updates` (array, required): Array of stock update objects
  - `id` (string, optional): Product UUID
  - `productNumber` (string, optional): Product number
  - `stock` (integer, required): New stock value
  - `threshold` (integer, optional): Stock threshold for cache invalidation

**Note**: Either `id` or `productNumber` must be provided for each update.

#### Response

```json
{
  "results": {
    "product-uuid-1": {
      "oldStock": 10,
      "newStock": 15
    },
    "product-uuid-2": {
      "oldStock": 5,
      "newStock": 25
    }
  }
}
```

## Event System

The plugin automatically dispatches events based on stock changes:

### ProductNoLongerAvailableEvent
- **Trigger**: When stock goes from positive to zero or negative
- **Use Case**: Handle out-of-stock scenarios

### InvalidateProductCache
- **Trigger**: 
  - When stock goes from zero/negative to positive (product becomes available)
  - When stock exceeds the specified threshold
- **Use Case**: Cache invalidation for improved performance

## Installation

1. Place the plugin in `custom/plugins/SwagAdvancedSyncAPI`
2. Run `bin/console plugin:refresh`
3. Run `bin/console plugin:install --activate SwagAdvancedSyncAPI`

## Development

### Running Tests

```bash
vendor/bin/phpunit custom/plugins/SwagAdvancedSyncAPI/tests/Integration/
```

### Plugin Structure

```
SwagAdvancedSyncAPI/
├── src/
│   ├── Api/
│   │   └── StockUpdateController.php
│   ├── Service/
│   │   └── StockUpdateService.php
│   ├── Resources/
│   │   └── config/
│   │       └── services.xml
│   └── SwagAdvancedSyncAPI.php
├── tests/
│   └── Integration/
│       └── StockUpdateControllerTest.php
├── composer.json
└── README.md
```

## Requirements

- Shopware 6.6+
- PHP 8.1+

## License

MIT