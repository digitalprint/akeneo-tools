<?php

namespace App\Command\Product;

use Akeneo\Pim\ApiClient\Exception\NotFoundHttpException;
use Akeneo\Pim\ApiClient\Search\SearchBuilder;
use App\Command\AbstractPimCommand;
use Exception;
use JsonException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pim:product-model:duplicate',
    description: 'Dupliziert ein bestehendes Akeneo Product-Model inklusive aller Untermodelle und Varianten.',
)]
class PimDuplicateProductModelCommand extends AbstractPimCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('source-code', InputArgument::OPTIONAL, 'Der Code (UUID) des Quell-Product-Models oder ein Suchbegriff (Name)')
            ->addArgument('new-name', InputArgument::OPTIONAL, 'Der Name für das neue Product-Model')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Schreibt die Änderungen direkt ins PIM (ohne Option nur Vorschau / Dry-Run).')
            ->setHelp(<<<EOT
Dieser Command dupliziert ein bestehendes Product-Model im Akeneo PIM samt aller Achsen und Varianten:

  <info>ddev php bin/console pim:product-model:duplicate</info>
  <info>ddev php bin/console pim:product-model:duplicate "00cbddc8-71fd-4cba-9c31-c8720f01176c" "Mein neues Wandbild" --force</info>

<comment>Hinweis zum Quell-Code:</comment>
Die Akeneo REST-API identifiziert Product-Models ausschließlich über ihren <info>Code</info> (in der Regel eine UUID).
Die Zahl am Ende einer PIM-Browser-URL (z. B. <info>https://pim.ppapi.de/#/enrich/product-model/1390</info>) ist lediglich eine interne Datenbank-ID der Weboberfläche.
Den tatsächlichen Code findest du in der PIM-Weboberfläche in den Eigenschaften des Modells unter <info>Code</info>.
Alternativ kannst du im interaktiven Modus direkt nach dem Modellnamen suchen.
EOT
            );
    }

    /**
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Akeneo PIM: Product-Model Duplizierung');

        $force = (bool) $input->getOption('force');

        // 1. Quell-Code abfragen (interaktiv oder Argument)
        $rawSourceCode = $input->getArgument('source-code');
        if (empty($rawSourceCode)) {
            $question = new Question('Bitte den Code (UUID) oder Suchbegriff (Name) des Quell-Product-Models eingeben: ');
            $question->setValidator(static function (?string $value) {
                if (empty(trim((string) $value))) {
                    throw new RuntimeException('Die Eingabe darf nicht leer sein.');
                }
                return trim((string) $value);
            });
            $rawSourceCode = $io->askQuestion($question);
        }

        $sourceCode = $this->parseSourceCode($rawSourceCode);

        // 2. Quell-Product-Model laden & validieren (mit Such-Fallback)
        $sourceModel = $this->resolveSourceModel($sourceCode, $rawSourceCode, $input, $io);
        if ($sourceModel === null) {
            return Command::FAILURE;
        }

        $sourceCode = $sourceModel['code'];
        $currentName = $this->extractModelName($sourceModel);
        $io->success(sprintf('Ausgewählt: "%s" [Code: %s] (Familie: %s, Variante: %s)', $currentName, $sourceCode, $sourceModel['family'] ?? '-', $sourceModel['family_variant']));

        // 3. Neuen Namen abfragen
        $newName = $input->getArgument('new-name');
        if (empty($newName)) {
            $question = new Question(sprintf('Neuer Name für das Product-Model (bisher: "%s"): ', $currentName), $currentName . ' (Kopie)');
            $newName = $io->askQuestion($question);
        }

        // 4. Struktur analysieren & Payloads vorbereiten
        $io->section('Analysiere Variantenstruktur...');

        $newRootUuid = Uuid::uuid4()->toString();
        $newRootModel = $this->cloneProductModel($sourceModel, $newRootUuid, null, $newName);

        $modelsToCreate = [$newRootModel];
        $productsToCreate = [];

        // Prüfen, ob Sub-Product-Models (2-stufige Variante) vorhanden sind
        $subModels = $this->fetchChildModels($sourceCode);

        if (!empty($subModels)) {
            $io->text(sprintf('Gefundene Sub-Modelle (Achse 1): %d', count($subModels)));

            foreach ($subModels as $subModel) {
                $newSubModelUuid = Uuid::uuid4()->toString();
                // Sub-Modelle behalten ihre Achsen-Werte, parent verweist auf das neue Root-Model
                $newSubModel = $this->cloneProductModel($subModel, $newSubModelUuid, $newRootUuid);
                $modelsToCreate[] = $newSubModel;

                // Produkte unterhalb des Sub-Modells laden
                $childProducts = $this->fetchChildProducts($subModel['code']);
                foreach ($childProducts as $product) {
                    $newProductUuid = Uuid::uuid4()->toString();
                    $productsToCreate[] = $this->cloneProduct($product, $newProductUuid, $newSubModelUuid);
                }
            }
        } else {
            // 1-stufige Variante: Produkte hängen direkt am Root-Model
            $childProducts = $this->fetchChildProducts($sourceCode);
            $io->text(sprintf('Gefundene Produktvarianten: %d', count($childProducts)));

            foreach ($childProducts as $product) {
                $newProductUuid = Uuid::uuid4()->toString();
                $productsToCreate[] = $this->cloneProduct($product, $newProductUuid, $newRootUuid);
            }
        }

        // 5. Zusammenfassung & Preview ausgeben
        $summaryTable = new Table($output);
        $summaryTable->setHeaders(['Typ', 'Anzahl', 'Neue UUID (Root)']);
        $summaryTable->addRow(['Product Models (Root + Sub)', count($modelsToCreate), $newRootUuid]);
        $summaryTable->addRow(['Produkt-Varianten', count($productsToCreate), '-']);
        $summaryTable->render();

        if (!$force) {
            $io->warning('DRY-RUN MODUS: Es wurden keine Daten an das PIM gesendet. Nutze --force für den Echtlauf.');
            return Command::SUCCESS;
        }

        // 6. Daten ins PIM schreiben (Top-Down)
        $io->section('Schreibe Daten ins PIM...');

        // 6.1 Product Models upserten
        $io->text(sprintf('Erstelle %d Product Model(s)...', count($modelsToCreate)));
        $this->upsertModels($modelsToCreate, $io);

        // 6.2 Produkt-Varianten upserten
        if (!empty($productsToCreate)) {
            $io->text(sprintf('Erstelle %d Produkt-Variante(n)...', count($productsToCreate)));
            $this->upsertProducts($productsToCreate, $io);
        }

        $io->success(sprintf('Product-Model erfolgreich dupliziert! Neue UUID: %s', $newRootUuid));

        return Command::SUCCESS;
    }

    private function parseSourceCode(string $input): string
    {
        $input = trim($input);

        // Unterstützt direkte URLs aus der PIM Weboberfläche (z. B. https://pim.ppapi.de/#/enrich/product-model/1390)
        if (preg_match('#/product-model/([^/?#]+)#', $input, $matches)) {
            return urldecode($matches[1]);
        }

        return $input;
    }

    private function resolveSourceModel(string $sourceCode, string $rawInput, InputInterface $input, SymfonyStyle $io): ?array
    {
        $io->section(sprintf('Lade Quell-Product-Model [%s]...', $sourceCode));

        try {
            return $this->pimClient->getProductModelApi()->get($sourceCode);
        } catch (NotFoundHttpException) {
            // Modell nicht direkt über Code gefunden
        } catch (Exception $e) {
            $io->error('Fehler beim Abrufen des Product-Models: ' . $e->getMessage());
            return null;
        }

        // Erklärung für numerische UI-IDs
        $isNumeric = is_numeric($sourceCode);
        if ($isNumeric) {
            $io->warning([
                sprintf('Product-Model mit Code [%s] wurde nicht gefunden.', $sourceCode),
                'HINWEIS: Die Nummer in der Browser-URL (z. B. /product-model/' . $sourceCode . ') ist eine interne Web-UI-ID.',
                'Die Akeneo REST-API benötigt den tatsächlichen Modell-Code (UUID), der in der PIM-Weboberfläche in den Eigenschaften des Modells unter "Code" steht.',
            ]);
        } else {
            $io->warning(sprintf('Product-Model mit Code [%s] wurde im PIM nicht gefunden.', $sourceCode));
        }

        // Such-Fallback anbieten
        $searchTerm = $isNumeric ? '' : $sourceCode;
        if (empty($searchTerm)) {
            $searchTerm = $io->ask('Bitte gib den Namen oder einen Suchbegriff des Product-Models ein (oder [Enter] zum Abbrechen)');
            if (empty(trim((string) $searchTerm))) {
                return null;
            }
        }

        $io->text(sprintf('Suche nach Product Models mit Begriff "%s"...', $searchTerm));
        $matches = $this->searchProductModels($searchTerm);

        if (empty($matches)) {
            $io->error(sprintf('Keine Product Models für den Suchbegriff "%s" gefunden.', $searchTerm));
            return null;
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        $choices = [];
        $modelMap = [];
        foreach ($matches as $match) {
            $name = $this->extractModelName($match);
            $family = $match['family'] ?? ($match['parent'] ? 'Sub-Model: ' . $match['parent'] : '-');
            $label = sprintf('%s | Code: %s (Familie: %s)', $name, $match['code'], $family);
            $choices[] = $label;
            $modelMap[$label] = $match;
        }

        if (!$input->isInteractive()) {
            $io->warning(sprintf('Mehrere Product Models für "%s" gefunden. Bitte den genauen Code angeben:', $searchTerm));
            foreach ($choices as $choice) {
                $io->writeln('  - ' . $choice);
            }
            return null;
        }

        $selectedLabel = $io->choice('Bitte wähle das gewünschte Quell-Product-Model aus:', $choices);

        return $modelMap[$selectedLabel] ?? null;
    }

    private function searchProductModels(string $searchTerm): array
    {
        $results = [];
        $seen = [];

        $scopes = [null, 'printplanet', 'merchrocket', 'photofancy_mag'];
        $locales = [null, 'de_DE', 'en_US'];

        foreach ($scopes as $scope) {
            foreach ($locales as $locale) {
                $searchBuilder = new SearchBuilder();
                $options = [];
                if ($scope !== null) {
                    $options['scope'] = $scope;
                }
                if ($locale !== null) {
                    $options['locale'] = $locale;
                }

                $searchBuilder->addFilter('name', 'CONTAINS', $searchTerm, $options);

                try {
                    $cursor = $this->pimClient->getProductModelApi()->all(25, [
                        'search' => $searchBuilder->getFilters(),
                    ]);

                    foreach ($cursor as $model) {
                        if (!isset($seen[$model['code']])) {
                            $seen[$model['code']] = true;
                            $results[] = $model;
                        }
                    }
                } catch (Exception) {
                    // Filterfehler ignorieren und nächste Kombination prüfen
                }

                if (count($results) >= 20) {
                    break 2;
                }
            }
        }

        return $results;
    }

    private function cloneProductModel(array $source, string $newUuid, ?string $newParent = null, ?string $newName = null): array
    {
        $clone = [
            'code' => $newUuid,
            'family_variant' => $source['family_variant'],
            'categories' => $source['categories'] ?? [],
            'values' => $source['values'] ?? [],
            'associations' => $source['associations'] ?? [],
        ];

        // Nur das Root-Model darf die Familie definieren, Sub-Modelle erben diese implizit
        if (null === $newParent && isset($source['family'])) {
            $clone['family'] = $source['family'];
        }

        if (null !== $newParent) {
            $clone['parent'] = $newParent;
        }

        // Namen aktualisieren, falls übergeben
        if ($newName !== null) {
            $clone['values'] = $this->updateNameInValues($clone['values'], $newName);
        }

        return $clone;
    }

    private function cloneProduct(array $source, string $newUuid, string $newParentUuid): array
    {
        // Varianten dürfen kein 'family'-Feld enthalten (führt sonst zu HTTP 422 im PIM)
        $clone = [
            'identifier' => $newUuid,
            'enabled' => $source['enabled'] ?? true,
            'categories' => $source['categories'] ?? [],
            'groups' => $source['groups'] ?? [],
            'parent' => $newParentUuid,
            'values' => $source['values'] ?? [],
            'associations' => $source['associations'] ?? [],
        ];

        return array_filter($clone, static fn($value) => $value !== null);
    }

    private function updateNameInValues(array $values, string $newName): array
    {
        if (isset($values['name']) && is_array($values['name'])) {
            foreach ($values['name'] as $idx => $entry) {
                $values['name'][$idx]['data'] = $newName;
            }
        } else {
            // Falls bisher kein Name gesetzt war
            $values['name'] = [
                ['locale' => null, 'scope' => null, 'data' => $newName],
            ];
        }

        return $values;
    }

    private function extractModelName(array $model): string
    {
        if (!isset($model['values']['name'])) {
            return $model['code'];
        }

        foreach ($model['values']['name'] as $entry) {
            if (!empty($entry['data'])) {
                return (string) $entry['data'];
            }
        }

        return $model['code'];
    }

    private function fetchChildModels(string $parentCode): array
    {
        $searchBuilder = new SearchBuilder();
        $searchBuilder->addFilter('parent', 'IN', [$parentCode]);

        $cursor = $this->pimClient->getProductModelApi()->all(100, [
            'search' => $searchBuilder->getFilters(),
        ]);

        return iterator_to_array($cursor);
    }

    private function fetchChildProducts(string $parentCode): array
    {
        $searchBuilder = new SearchBuilder();
        $searchBuilder->addFilter('parent', 'IN', [$parentCode]);

        $cursor = $this->pimClient->getProductApi()->all(100, [
            'search' => $searchBuilder->getFilters(),
        ]);

        return iterator_to_array($cursor);
    }

    /**
     * @throws JsonException
     */
    private function upsertModels(array $models, SymfonyStyle $io): void
    {
        foreach (array_chunk($models, 100) as $chunk) {
            $responses = $this->pimClient->getProductModelApi()->upsertList($chunk);
            foreach ($responses as $response) {
                $identifier = $response['code'] ?? $response['identifier'] ?? 'unbekannt';
                if ($response['status_code'] >= 200 && $response['status_code'] < 300) {
                    $io->writeln(sprintf('  <info>[OK %d]</info> Model: %s', $response['status_code'], $identifier));
                } else {
                    $io->writeln(sprintf('  <error>[ERR %d]</error> Model: %s - %s', $response['status_code'], $identifier, json_encode($response, JSON_THROW_ON_ERROR)));
                }
            }
        }
    }

    /**
     * @throws JsonException
     */
    private function upsertProducts(array $products, SymfonyStyle $io): void
    {
        foreach (array_chunk($products, 100) as $chunk) {
            $responses = $this->pimClient->getProductApi()->upsertList($chunk);
            foreach ($responses as $response) {
                $identifier = $response['identifier'] ?? $response['code'] ?? 'unbekannt';
                if ($response['status_code'] >= 200 && $response['status_code'] < 300) {
                    $io->writeln(sprintf('  <info>[OK %d]</info> Variant: %s', $response['status_code'], $identifier));
                } else {
                    $io->writeln(sprintf('  <error>[ERR %d]</error> Variant: %s - %s', $response['status_code'], $identifier, json_encode($response, JSON_THROW_ON_ERROR)));
                }
            }
        }
    }
}
