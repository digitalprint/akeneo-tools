# Akeneo PHP API Client: Anwendungsbeispiele

## 1. Paginierter Massenabruf (Cursor)

```php
use Akeneo\Pim\ApiClient\Search\SearchBuilder;

$searchBuilder = new SearchBuilder();
$searchBuilder
    ->addFilter('family', '=', 'wandbilder')
    ->addFilter('enabled', '=', true);

$cursor = $this->pimClient->getProductApi()->all(100, [
    'search' => $searchBuilder->getFilters(),
    'scope'  => 'merchrocket',
    'locales'=> 'de_DE',
]);

foreach ($cursor as $product) {
    $sku = $product['identifier'];
    $name = $product['values']['name'][0]['data'] ?? 'Ohne Name';
    // Verarbeitung...
}
```

---

## 2. Massen-Upsert mit Fehlerprotokollierung

```php
$productsToUpsert = [/* Array von Produkt-Payloads */];

foreach (array_chunk($productsToUpsert, 100) as $chunk) {
    try {
        $responses = $this->pimClient->getProductApi()->upsertList($chunk);
        
        foreach ($responses as $res) {
            $status = $res['status_code'];
            $identifier = $res['identifier'];
            
            if ($status >= 200 && $status < 300) {
                $this->output->writeln("<info>[OK $status]</info> $identifier");
            } else {
                $this->output->writeln("<error>[ERR $status]</error> $identifier: " . json_encode($res, JSON_THROW_ON_ERROR));
            }
        }
    } catch (\Throwable $e) {
        $this->output->writeln("<error>Batch-Fehler: " . $e->getMessage() . "</error>");
    }
}
```

---

## 3. Top-Down Anlegen von 2-stufigen Variantenstrukturen

```php
use Ramsey\Uuid\Uuid;

// 1. Root Product Model anlegen
$rootCode = Uuid::uuid4()->toString();
$rootModel = [
    'code' => $rootCode,
    'family' => 'wandbilder',
    'family_variant' => 'wandbilder_mat_size',
    'values' => [
        'name' => [['locale' => null, 'scope' => null, 'data' => 'Foto auf Acrylglas']],
    ],
];
$this->pimClient->getProductModelApi()->upsert($rootCode, $rootModel);

// 2. Sub-Product Model (Achse 1: Material)
$subCode = Uuid::uuid4()->toString();
$subModel = [
    'code' => $subCode,
    'family_variant' => 'wandbilder_mat_size',
    'parent' => $rootCode,
    'values' => [
        'material' => [['locale' => null, 'scope' => null, 'data' => 'acrylglas_5mm']],
    ],
];
$this->pimClient->getProductModelApi()->upsert($subCode, $subModel);

// 3. Produktvariante (Achse 2: Format / Größe)
$variantSku = Uuid::uuid4()->toString();
$variant = [
    'identifier' => $variantSku,
    'enabled' => true,
    'parent' => $subCode,
    'values' => [
        'size' => [['locale' => null, 'scope' => null, 'data' => '80x60']],
        'base_price' => [
            ['locale' => null, 'scope' => null, 'data' => [['amount' => '49.95', 'currency' => 'EUR']]],
        ],
    ],
];
$this->pimClient->getProductApi()->upsert($variantSku, $variant);
```

---

## 4. Attribut-Werte sicher aktualisieren / injecten

```php
function setAttributeValue(array &$product, string $attrCode, mixed $data, ?string $scope = null, ?string $locale = null): void
{
    if (!isset($product['values'][$attrCode])) {
        $product['values'][$attrCode] = [];
    }

    $found = false;
    foreach ($product['values'][$attrCode] as &$entry) {
        if ($entry['scope'] === $scope && $entry['locale'] === $locale) {
            $entry['data'] = $data;
            $found = true;
            break;
        }
    }
    unset($entry);

    if (!$found) {
        $product['values'][$attrCode][] = [
            'scope' => $scope,
            'locale' => $locale,
            'data' => $data,
        ];
    }
}
```
