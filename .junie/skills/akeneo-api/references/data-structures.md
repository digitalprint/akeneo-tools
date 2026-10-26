# Akeneo PIM: Datenstrukturen & Attributtypen

## 1. Produkt-Payload (`Product`)

```json
{
  "identifier": "018f232b-3134-7000-8801-123456789abc",
  "enabled": true,
  "family": "wandbilder",
  "parent": "model-uuid-or-code",
  "categories": ["wandbilder_leinwand"],
  "groups": [],
  "values": {
    "name": [
      { "locale": null, "scope": null, "data": "Foto auf Leinwand 40x30" }
    ],
    "description": [
      { "locale": "de_DE", "scope": "merchrocket", "data": "<p>Hochwertiger Leinwanddruck</p>" }
    ],
    "base_price": [
      {
        "locale": null,
        "scope": null,
        "data": [
          { "amount": "24.90", "currency": "EUR" }
        ]
      }
    ],
    "printarea_width": [
      {
        "locale": null,
        "scope": null,
        "data": {
          "amount": 400.0,
          "unit": "MILLIMETER"
        }
      }
    ],
    "is_in_stock": [
      { "locale": null, "scope": null, "data": true }
    ],
    "orientation": [
      { "locale": null, "scope": null, "data": "landscape" }
    ],
    "custom_json_mapping": [
      { "locale": null, "scope": null, "data": "[{\"key\": \"DOCUMENT_SIZE\", \"val\": \"40x30\"}]" }
    ]
  }
}
```

---

## 2. Product-Model-Payload (`ProductModel`)

### Root-Model (2-stufig oder 1-stufig):
```json
{
  "code": "1390",
  "family": "wandbilder",
  "family_variant": "wandbilder_material_size",
  "parent": null,
  "categories": ["wandbilder"],
  "values": {
    "name": [
      { "locale": null, "scope": null, "data": "Foto auf Leinwand" }
    ]
  }
}
```

### Sub-Model (Achse 1 bei 2-stufigen Varianten):
```json
{
  "code": "1390-alu-dibond",
  "family_variant": "wandbilder_material_size",
  "parent": "1390",
  "values": {
    "material": [
      { "locale": null, "scope": null, "data": "alu_dibond" }
    ]
  }
}
```

---

## 3. Attributtypen im Detail

| Attributtyp                  | Akeneo Typ-Code                         | Payload `data`-Format       | Beispiel                                   |
|------------------------------|-----------------------------------------|-----------------------------|--------------------------------------------|
| **Einfacher Text**           | `pim_catalog_text`                      | `string`                    | `"Mein Produkt"`                           |
| **Mehrzeiliger Text / HTML** | `pim_catalog_textarea`                  | `string` (HTML/Plain)       | `"<p>Beschreibung</p>"`                    |
| **Zahl**                     | `pim_catalog_number`                    | `int`, `float`, `string`    | `42` oder `"42.5"`                         |
| **Preis**                    | `pim_catalog_price_collection`          | `array` von Objekten        | `[{"amount": "19.99", "currency": "EUR"}]` |
| **Maßeinheit**               | `pim_catalog_metric`                    | `object` (`amount`, `unit`) | `{"amount": 500, "unit": "MILLIMETER"}`    |
| **Einfache Auswahl**         | `pim_catalog_simpleselect`              | `string` (Optionscode)      | `"color_red"`                              |
| **Mehrfachauswahl**          | `pim_catalog_multiselect`               | `array` von Strings         | `["tag_sale", "tag_eco"]`                  |
| **Ja/Nein**                  | `pim_catalog_boolean`                   | `bool`                      | `true` / `false`                           |
| **Datum**                    | `pim_catalog_date`                      | `string` (ISO 8601 Datum)   | `"2026-10-26"`                             |
| **Bild / Datei**             | `pim_catalog_image`, `pim_catalog_file` | `string` (Media File Code)  | `"a/b/c/foto_abc123.jpg"`                  |

---

## 4. Filter-Operatoren für `SearchBuilder`

Folgende Operatoren stehen für Filterungen zur Verfügung:

- `=` (Gleich)
- `!=` (Ungleich)
- `IN` (In Liste enthalten)
- `NOT IN` (Nicht in Liste enthalten)
- `CONTAINS` (Enthält Teilstring)
- `DOES NOT CONTAIN` (Enthält keinen Teilstring)
- `STARTS WITH` (Beginnt mit)
- `ENDS WITH` (Endet mit)
- `<` / `<=` (Kleiner / Kleiner-gleich)
- `>` / `>=` (Größer / Größer-gleich)
- `EMPTY` / `NOT EMPTY` (Ist leer / Nicht leer)
- `BETWEEN` / `NOT BETWEEN` (Zwischen Werten)
