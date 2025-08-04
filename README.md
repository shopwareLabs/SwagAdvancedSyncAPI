# SwagAdvancedSyncAPI

An advanced synchronization API plugin for Shopware that provides enhanced stock management capabilities.

## Features

- **Advanced Stock Update API**: Update product stock with intelligent event dispatching and availability calculation
- **Product Resolution**: Update products by ID or product number
- **Availability Management**: Automatically calculates and updates the available flag based on stock, closeout status, and minimum purchase quantity
- **Event Integration**: Automatic cache invalidation and stock status events
- **Threshold Support**: Trigger cache invalidation when stock exceeds custom thresholds
- **Symfony Validation**: Comprehensive input validation with detailed error responses

## API Endpoints

### Stock Update

**POST** `/api/_action/swag-advanced-sync/stock-update`

Update stock levels for multiple products with advanced event handling and automatic availability calculation.

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
      "newStock": 15,
      "oldAvailable": true,
      "newAvailable": true
    },
    "product-uuid-2": {
      "oldStock": 5,
      "newStock": 25,
      "oldAvailable": false,
      "newAvailable": true
    }
  }
}
```

## Event System

The plugin automatically dispatches events based on stock changes:

### ProductNoLongerAvailableEvent
- **Trigger**: When product availability changes from available to unavailable
- **Use Case**: Handle out-of-stock scenarios, remove from listings

### InvalidateProductCache
- **Trigger**: 
  - When product availability changes from unavailable to available
  - When stock exceeds the specified threshold
- **Use Case**: Update product listings and detail pages

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
