# Entwicklungs- und Architektur-Guidelines: Akeneo Tools

Dieses Dokument definiert verbindliche Standards, Architekturkonzepte und Best Practices für die Entwicklung und Ausführung von Jobs zur automatisierten Kommunikation mit dem **Akeneo PIM**. 

Das primäre Ziel dieser Guidelines ist es, maximale Entwicklungseffizienz, hohe Ausfallsicherheit bei Massendaten-Operationen sowie fehlerfreie Datenstrukturen im PIM zu gewährleisten.

---

## 1. Projektübersicht & Zweck

Das Projekt ist eine spezialisierte **Symfony Console Application**, die als Middleware/Batch-Verarbeitungswerkzeug dient.

### Hauptaufgaben:
- **Massenanlage und -aktualisierung** von komplexen Produktstrukturen (Familien, Variantenmodelle, Produktvarianten).
- **Preis-Updates & Preismatrix-Kalkulationen** (Grundpreise, Staffelpreise, Aufpreise für Sondermaße/Materialien).
- **Automatisierte Zuordnungen & Mappings** (z. B. Printess-Dokumentformate, Ausrichtungen, Lieferanten-SKUs).
- **Datenmigrationen & Bereinigungen** zwischen PIM-Instanzen (Staging/Production) oder Attributstrukturen.

---

## 2. Entwicklungsumgebung mit DDEV

Die gesamte Entwicklung und Ausführung erfolgt standardisiert über **DDEV** (PHP 8.2).

> **Wichtiger Hinweis zur Befehlsausführung:**
> Alle Console-Commands müssen **ausnahmslos über DDEV** ausgeführt werden (z. B. `ddev php bin/console ...` oder `ddev exec php bin/console ...`). Auf dem lokalen Host-System läuft häufig eine abweichende PHP-Version (z. B. PHP 8.5) oder eine andere Umgebung als im Projekt-Container. Die Ausführung direkt auf dem Host führt daher zu Inkompatibilitäten, Versionskonflikten oder unerwarteten Fehlern. Innerhalb von DDEV ist die exakt passende PHP-Version (8.2) inklusive aller erforderlichen Extensions und Abhängigkeiten garantiert.

### Wichtige Befehle:

| Aktion                                     | Befehl                                            |
|--------------------------------------------|---------------------------------------------------|
| DDEV starten                               | `ddev start`                                      |
| Composer Abhängigkeiten                    | `ddev composer install`                           |
| CLI / Jobs anzeigen                        | `ddev php bin/console list`                       |
| PIM Verbindung / Auth testen               | `ddev php bin/console pim:test:connection`        |
| Interaktiver Job-Start (Dry-Run / Preview) | `ddev php bin/console pim:product:upsert`         |
| Job mit Ausführung auf PIM (Echtlauf)      | `ddev php bin/console pim:product:upsert --force` |

### Umgebungsvariablen (`.env.local`):
Zugangsdaten für die PIM-API werden ausschließlich über `.env.local` konfiguriert und dürfen niemals committed werden:

```env
PIM_API_URL=https://ihr-akeneo-host.com
PIM_CLIENT_ID=your_client_id
PIM_CLIENT_SECRET=your_client_secret
PIM_CLIENT_USER=your_api_username
PIM_CLIENT_PASS=your_api_password
```

---

## 3. Architektur & Job-Muster

Das System basiert auf einer strikten Trennung zwischen dem zentralen Ausführungs-Command (`PimUpsertCommand`) und den fachlichen Job-Implementierungen (`JobInterface`).

```
src/
├── Command/
│   └── Product/
│       ├── PimUpsertCommand.php             # Zentraler interaktiver Dispatcher
│       └── Jobs/
│           ├── JobInterface.php             # Interface für alle Jobs
│           ├── AbstractJob.php              # Basisklasse mit Hilfsmethoden
│           └── <JobName>/                   # Eigener Ordner pro Fachlogik
│               ├── <JobName>Job.php         # Konkrete Job-Klasse
│               ├── attributes.json          # Attribut-Skelette / Defaults
│               ├── sizes.json               # Formatdefinitionen (optional)
│               ├── flip.json / mapping.json # Lieferanten-/Materialmappings (optional)
│               └── materials/               # Detaillierte Materialkonfigurationen
```

### 3.1 Das Dry-Run-Prinzip (Sicherheit geht vor)

Um unbeabsichtigte Massenänderungen im PIM zu verhindern:
1. **Default ist immer Preview/Dry-Run:** Ohne `--force` werden alle Berechnungen und Mappings durchgeführt und betroffene Produkte in der Konsole geloggt – es findet **kein Schreibzugriff** auf das PIM statt.
2. **Echtlauf nur mit `--force`:** Erst wenn `execute(bool $force = true)` übergeben wird, werden Daten an die PIM-API gesendet.

### 3.2 Ablauf eines Jobs

Ein Standard-Job durchläuft folgende Phasen:
1. **Initialisierung & Laden von Fixtures:** JSON-/CSV-Konfigurationsdateien im Job-Verzeichnis einlesen.
2. **Abruf bestehender Daten:** PIM-Daten über Suchkriterien (`SearchBuilder`, Parent-UUIDs, etc.) ermitteln.
3. **Transformation / Generierung:**
   - Bestehendes Produkt aktualisieren oder neues Skeleton erzeugen (`getProductVariantSkeleton`).
   - Attribute mit korrekter Scope- und Locale-Zuordnung setzen (`setAttributeValueInProduct`).
   - Preise, Staffelpreise oder Formular-Mappings berechnen.
4. **Validierung & Sammeln:** Resultierende Produkt-Payloads in einem Array bündeln.
5. **Batch-Upsert:** Über `runUpsert()` oder `runModelUpsert()` in Chunks an die PIM API senden.

---

## 4. Richtlinien für die Erstellung neuer Jobs

### Schritt 1: Verzeichnis anlegen
Erstelle unter `src/Command/Product/Jobs/<NeuerJobName>/` die Job-Klasse und alle zugehörigen Konfigurationsdateien.

### Schritt 2: Job-Klasse implementieren
Jeder Job erweitert `AbstractJob` und implementiert `JobInterface`:

```php
<?php

namespace App\Command\Product\Jobs\NeuerJob;

use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use App\Command\Product\Jobs\AbstractJob;
use Symfony\Component\Console\Output\Output;

class NeuerJob extends AbstractJob
{
    protected string $productFamily = 'ihre_produktfamilie';

    public function __construct(Output $output, AkeneoPimClientInterface $pimClient)
    {
        parent::__construct($output, $pimClient);

        // Lokale Konfigurationsdateien laden
        $attributesFile = __DIR__ . '/attributes.json';
        if (is_file($attributesFile)) {
            $this->possibleAttributes = json_decode(file_get_contents($attributesFile), true, 512, JSON_THROW_ON_ERROR);
        }
    }

    public function execute(bool $force = false): void
    {
        $products = [];
        $resultInfo = [];

        // 1. Logik zur Datenverarbeitung & Berechnung
        // ...

        // 2. Ausführung / Vorschau
        $this->runUpsert($products, $resultInfo, $force);
    }
}
```

### Schritt 3: Im `PimUpsertCommand` registrieren
Füge die neue Klasse in `src/Command/Product/PimUpsertCommand.php` zum `$availableJobs`-Array hinzu:

```php
private array $availableJobs = [
    // ...
    NeuerJob::class,
];
```

---

## 5. Best Practices für Akeneo PIM Interaktionen

### 5.1 Batching & API-Performance
- **Immer `upsertList()` verwenden:** Einzelne API-Calls für jedes Produkt sind ineffizient und verlangsamen Massenupdates drastisch.
- **Chunk-Größe:** Payloads standardmäßig in Chunks von **100 Produkten** verarbeiten (bereits in `runUpsert()` implementiert).
- **Ressourcenschnittstellen:** Memory Limit und Execution Time sind im Command bereits großzügig bemessen (`ini_set('memory_limit', '1024M')`), Speicher bei großen Schleifen dennoch schonend behandeln.

### 5.2 Attribute, Scopes und Locales
Akeneo-Attribute haben unterschiedliche Gültigkeiten (global vs. scope-spezifisch vs. sprachabhängig):
- Verwende `setAttributeValueInProduct($product, $attributeName, $value, $scope, $locale)`.
- Achte auf die korrekten Datentypen:
  - **Preise:** Erfordern ein Array mit Betrag und Währung (z. B. `[['amount' => '19.99', 'currency' => 'EUR']]`).
  - **Maßeinheiten:** Erfordern Betrag und Einheit (z. B. `['amount' => 500, 'unit' => 'MILLIMETER']`).
  - **Booleans:** Explizit als `bool` (`true`/`false`) casten.
  - **JSON-/Mapping-Werte:** Als validen JSON-String übergeben (z. B. für Printess/Konfigurator).

### 5.3 Fehlerbehandlung & Logging
- **HTTP 422 Unprocessable Entity:** Tritt auf, wenn PIM-Validierungsregeln verletzt werden. Das `AbstractJob::runUpsert()` fängt diese ab und gibt die exakte Fehlermeldung aus dem PIM aus.
- **Transparente Logs:** In `$resultInfo` prägnante Informationen (Name, Material, Größe, SKU) hinterlegen, damit der Operator im Dry-Run sofort sieht, was passiert.

---

## 6. Code Style & Qualitätsstandards

- **PHP-Version:** Mindestens PHP 8.1 / 8.2 kompatibler Code.
- **Typisierung:** Strikte Typdeklarationen bei Methoden-Signaturen und Properties.
- **JSON Handling:** Immer mit `JSON_THROW_ON_ERROR` arbeiten, um Parsing-Fehler frühzeitig zu erkennen.
- **Keine Hardcoded Secrets:** Zugangsdaten und Tokens dürfen ausschließlich über `.env` / DDEV Umgebungsvariablen bezogen werden.
- **Saubere Git-Historie:** Temporäre Test-Dateien (`backup.json`, temporäre CSVs) vor dem Commit prüfen und nicht versehentlich ins Repository einchecken.
