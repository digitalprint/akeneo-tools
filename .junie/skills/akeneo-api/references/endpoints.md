# Akeneo PIM REST API: Endpunkte

## 1. Übersicht der API-Bereiche

Der Akeneo PHP Client stellt für alle wesentlichen Entitäten eigene API-Instanzen über Getter-Methoden bereit:

| Entität                | PHP Client Methode                          | REST Endpunkt                                             |
|------------------------|---------------------------------------------|-----------------------------------------------------------|
| **Products**           | `$pimClient->getProductApi()`               | `/api/rest/v1/products` oder `/api/rest/v1/products-uuid` |
| **Product Models**     | `$pimClient->getProductModelApi()`          | `/api/rest/v1/product-models`                             |
| **Families**           | `$pimClient->getFamilyApi()`                | `/api/rest/v1/families`                                   |
| **Family Variants**    | `$pimClient->getFamilyVariantApi()`         | `/api/rest/v1/families/{family}/variants`                 |
| **Attributes**         | `$pimClient->getAttributeApi()`             | `/api/rest/v1/attributes`                                 |
| **Attribute Options**  | `$pimClient->getAttributeOptionApi()`       | `/api/rest/v1/attributes/{attribute}/options`             |
| **Categories**         | `$pimClient->getCategoryApi()`              | `/api/rest/v1/categories`                                 |
| **Channels**           | `$pimClient->getChannelApi()`               | `/api/rest/v1/channels`                                   |
| **Locales**            | `$pimClient->getLocaleApi()`                | `/api/rest/v1/locales`                                    |
| **Currencies**         | `$pimClient->getCurrencyApi()`              | `/api/rest/v1/currencies`                                 |
| **Media Files**        | `$pimClient->getProductMediaFileApi()`      | `/api/rest/v1/media-files`                                |
| **Reference Entities** | `$pimClient->getReferenceEntityApi()`       | `/api/rest/v1/reference-entities`                         |
| **Reference Records**  | `$pimClient->getReferenceEntityRecordApi()` | `/api/rest/v1/reference-entities/{entity}/records`        |

---

## 2. Standard-CRUD & Batch-Methoden pro API

Jede API-Klasse im PHP Client unterstützt standardmäßig:

- **`get(string $code, array $queryParameters = []): array`**
  Ruft eine einzelne Entität anhand ihres Codes / Identifiers ab.
- **`create(string $code, array $data = []): int`**
  Erstellt eine Entität (nur für Entitäten ohne `upsert`).
- **`upsert(string $code, array $data = []): int`**
  Erstellt oder aktualisiert eine einzelne Entität.
- **`upsertList(\Traversable|array $list): \Iterator`**
  Batch-Upsert für bis zu 100 Entitäten pro Request. Gibt einen Iterator über die Responses mit Status-Codes zurück.
- **`delete(string $code): int`**
  Löscht eine Entität anhand ihres Codes.
- **`listPerPage(int $limit = 10, bool $withCount = false, array $queryParameters = []): PageInterface`**
  Paginierter Abruf einer einzelnen Seite.
- **`all(int $pageSize = 10, array $queryParameters = []): CursorInterface`**
  Cursor-basierte Iteration über alle passenden Entitäten.

---

## 3. Query-Parameter

Beim Abruf von Produkten oder Produktmodellen können folgende Query-Parameter übergeben werden:

- `search`: Filter-Array (generiert via `SearchBuilder->getFilters()`)
- `scope`: Channel-Code (z. B. `'ecommerce'`, `'merchrocket'`)
- `locales`: Komma-separierte Sprachcodes (z. B. `'de_DE,en_US'`)
- `attributes`: Komma-separierte Liste von Attributen, die zurückgegeben werden sollen (reduziert Payload)
- `pagination_type`: `'page'` oder `'search_after'` (standardmäßig bei `all()` automatisch per Cursor gesteuert)
