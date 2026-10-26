---
name: akeneo-api
description: "Akeneo PIM REST API und PHP Client Referenz: Best Practices für Code-Generierung, Endpoints, Filter-Syntax, Payload-Strukturen, Variantenhierarchien und Batch-Upserts."
---

# Akeneo PIM REST API & PHP Client Skill

Dieser Skill liefert verbindliche Richtlinien, Code-Muster und Referenzstrukturen für die Arbeit mit der **Akeneo PIM REST API** sowie dem offiziellen **Akeneo PHP API Client** (`akeneo/api-php-client`).

---

## 1. Kernprinzipien & Best Practices

1. **Batching über `upsertList()`:**
   - Massenoperationen (Create / Update) **immer** gebündelt in Chunks von maximal 100 Objekten über `upsertList($chunk)` ausführen.
   - Einzelne `create()` / `upsert()`-Aufrufe in Schleifen sind ineffizient und zu vermeiden.

2. **Top-Down-Reihenfolge bei Variantenhierarchien:**
   - **Root Product-Model:** Muss zuerst existieren (`parent = null`).
   - **Sub-Product-Model (Achse 1):** Verweist auf Root Product-Model (`parent = <root_code>`).
   - **Produktvariante (`Product`):** Verweist auf Root Product-Model (bei 1-stufig) oder Sub-Product-Model (bei 2-stufig) (`parent = <model_code>`).

3. **Attribut-Werteformat (Scopes & Locales):**
   - Jeder Attributwert in `values` ist ein Array von Objekten mit den Schlüsseln `locale`, `scope` und `data`:
     ```php
     'values' => [
         'my_attribute' => [
             ['locale' => null, 'scope' => null, 'data' => 'Global Value'],
             ['locale' => 'de_DE', 'scope' => 'ecommerce', 'data' => 'Lokalisierter Scoped Value'],
         ],
     ]
     ```

4. **Datentypen & Formatierung:**
   - **Text / Textarea:** String.
   - **Zahlen / Number:** Integer, Float oder numerischer String.
   - **Booleans:** Explizit `true` oder `false`.
   - **Preise (`pim_catalog_price_collection`):** Array von Preisobjekten:
     ```php
     [['amount' => '29.99', 'currency' => 'EUR']]
     ```
   - **Maßeinheiten (`pim_catalog_metric`):** Array mit Betrag und Einheit:
     ```php
     ['amount' => 150.5, 'unit' => 'MILLIMETER']
     ```
   - **Option / Simpleselect:** Code der Attributoption als String (`'option_code'`).
   - **Multiselect:** Array von Options-Codes (`['option_1', 'option_2']`).

5. **Payload-Bereinigung vor dem Schreiben:**
   - Read-only-Felder wie `created`, `updated`, `metadata`, `_links`, `quality_scores` dürfen niemals im Upsert-Payload enthalten sein.

---

## 2. API-Client-Initialisierung & Authentifizierung

```php
use Akeneo\Pim\ApiClient\AkeneoPimClientBuilder;
use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;

$clientBuilder = new AkeneoPimClientBuilder($apiUrl);
$pimClient = $clientBuilder->buildAuthenticatedByPassword(
    $clientId,
    $clientSecret,
    $username,
    $password
);
```

In Symfony Commands des Projekts wird von `App\Command\AbstractPimCommand` geerbt, wodurch `$this->pimClient` automatisch initialisiert bereitsteht.

---

## 3. Typische Interaktionsmuster

### 3.1 Suchen & Filtern mit `SearchBuilder`
```php
use Akeneo\Pim\ApiClient\Search\SearchBuilder;

$searchBuilder = new SearchBuilder();
$searchBuilder
    ->addFilter('family', 'IN', ['wandbilder', 'schilder'])
    ->addFilter('enabled', '=', true)
    ->addFilter('parent', '=', $parentModelCode);

$searchFilters = $searchBuilder->getFilters();

// Paginierter Abruf mit Generator / Cursor
$cursor = $pimClient->getProductApi()->all(100, [
    'search' => $searchFilters,
    'scope'  => 'merchrocket',
    'locales'=> 'de_DE',
]);

foreach ($cursor as $product) {
    // Verarbeitung einzelner Produkte
}
```

### 3.2 Massen-Upsert mit Fehlerprüfung
```php
foreach (array_chunk($products, 100) as $chunk) {
    $responses = $pimClient->getProductApi()->upsertList($chunk);
    
    foreach ($responses as $response) {
        $code = $response['status_code'];
        $identifier = $response['identifier'] ?? $response['code'] ?? 'unknown';
        
        if ($code >= 200 && $code < 300) {
            // Erfolg (201 Created / 204 No Content / 200 OK)
            $output->writeln("[OK $code] $identifier");
        } else {
            // Validierungsfehler (422 Unprocessable Entity) oder API-Fehler
            $output->writeln("<error>[ERR $code] $identifier: " . json_encode($response, JSON_THROW_ON_ERROR) . "</error>");
        }
    }
}
```

---

## 4. Referenzdokumente

Detaillierte Spezifikationen und Syntaxbeispiele befinden sich in den Unterdokumenten:
- [Endpoint-Übersicht](references/endpoints.md): Vollständige Liste der REST-Routen für Produkte, Modelle, Familien, Attribute und Medien.
- [Datenstrukturen & Attribute](references/data-structures.md): Detaillierte Payloads für alle Akeneo-Attributtypen, Metriken und Preisstrukturen.
- [PHP Client Anwendungsbeispiele](references/php-client-examples.md): Konkrete Code-Snippets für Duplizierung, Migration, Attribut-Handling und Media-Upload.
