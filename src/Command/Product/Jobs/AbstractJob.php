<?php

/** @noinspection CallableParameterUseCaseInTypeContextInspection */

namespace App\Command\Product\Jobs;

use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use Akeneo\Pim\ApiClient\Search\SearchBuilder;
use Exception;
use Illuminate\Support\Arr;
use JsonException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\Console\Output\Output;

class AbstractJob implements JobInterface
{
    protected const SHOP_TAX = 1.19;

    protected const DEFAULT_LOCALE = 'de_DE';

    protected const DEFAULT_SCOPE = 'merchrocket';

    /**
     * @var Output
     */
    protected Output $output;

    protected AkeneoPimClientInterface $pimClient;

    protected array $possibleAttributes = [];

    protected array $flipSkus = [];

    public function __construct(Output $output, AkeneoPimClientInterface $pimClient)
    {
        $this->output = $output;

        $this->pimClient = $pimClient;
    }

    protected function getProductVariantSkeleton(string $family, string $parentUuid = null, string $uuid = null, $category = null): array
    {
        $productVariant = [
            'enabled' => true,
            'family' => $family,
            'groups' => [],
            'values' => [],
        ];

        if (null === $uuid) {
            $uuid = Uuid::uuid4()->toString();
        }
        $productVariant['identifier'] = $uuid;

        if (null !== $parentUuid) {
            $productVariant['parent'] = $parentUuid;
        }

        if (null !== $category) {
            $productVariant['categories'] = [$category];
        }

        return $productVariant;
    }

    protected function getChildProductsByUuid(string $parentUuid, string $scope = self::DEFAULT_SCOPE, string $locales = 'en_US'): array
    {
        $searchBuilder = new SearchBuilder();
        $searchBuilder
            ->addFilter('parent', '=', $parentUuid);
        $searchFilters = $searchBuilder->getFilters();

        $cursor = $this->pimClient->getProductApi()->all(100, [
            'search' => $searchFilters,
            'scope' => $scope,
            'locales' => $locales
        ]);

        return iterator_to_array($cursor);
    }

    protected function getProductsByUuid(string $Uuid, string $scope = self::DEFAULT_SCOPE, string $locales = 'en_US'): array
    {
        return $this->pimClient->getProductApi()->get($Uuid, [
            'scope' => $scope,
            'locales' => $locales
        ]);
    }

    protected function getProductModelByCode(string $Uuid, string $scope = self::DEFAULT_SCOPE, string $locales = 'en_US'): array
    {
        return $this->pimClient->getProductModelApi()->get($Uuid, [
            'scope' => $scope,
            'locales' => $locales
        ]);
    }

    protected function getProductByFlipSku(string $sku): ?array
    {
        $searchBuilder = new SearchBuilder();
        $searchBuilder
            ->addFilter('supplier_sku', '=', $sku);
        $searchFilters = $searchBuilder->getFilters();

        $cursor = $this->pimClient->getProductApi()->all(10, ['search' => $searchFilters, 'scope' => self::DEFAULT_SCOPE]);

        return iterator_to_array($cursor)[0] ?? null;
    }

    public function execute(bool $force = false): void
    {
    }

    protected function findProductInProductsByAttributeAndValue(array $products, string $attributeName, string $attributeValue): array
    {
        return array_filter($products, static function ($product) use ($attributeName, $attributeValue) {
            if (! isset($product['values'][$attributeName])) {
                throw new RuntimeException("The attribute [$attributeName] could not be found in product [" . $product['name'] . '].');
            }

            return $product['values'][$attributeName][0]['data'] === $attributeValue;
        });
    }

    /**
     * @throws JsonException
     */
    protected function setAttributeValueInProduct(array $product, string $attributeName, string $attributeValue, string $scope = null, string $locale = null): array
    {
        if (! isset($product['values'][$attributeName])) {
            if (! isset($this->possibleAttributes[$attributeName])) {
                throw new RuntimeException("You need to get an actual attribute list. The attribute Skeleton of [$attributeName]] cannot be found in the attribute list.");
            }

            $product['values'][$attributeName] = $this->possibleAttributes[$attributeName];
        }

        foreach ($product['values'][$attributeName] as $index => $attribute) {

            $setter = 'values.' . $attributeName . '.' . $index . '.data';

            if ('base_price' === $attributeName) {
                $attributeValue = [
                    ['amount' => $attributeValue, 'currency' => 'EUR'],
                ];
            }

            if (in_array($attributeName, ['printarea_width', 'printarea_height'], true)) {
                $attributeValue = ['amount' => $attributeValue, 'unit' => 'MILLIMETER'];
            }

            if (in_array($attributeName, ['is_in_stock', 'active'], true)) {
                $attributeValue = (bool) $attributeValue;
            }

            if (null === $attribute['locale'] && null === $attribute['scope']) {
                Arr::set($product, $setter, $attributeValue);
            } elseif ($attribute['locale'] === $locale && null === $attribute['scope']) {
                Arr::set($product, $setter, $attributeValue);
            } elseif (null === $attribute['locale'] && $scope === $attribute['scope']) {
                Arr::set($product, $setter, $attributeValue);
            } elseif ($attribute['locale'] === $locale && $attribute['scope'] === $scope) {
                Arr::set($product, $setter, $attributeValue);
            } else {
                throw new RuntimeException('Could not set the attribute[' . $attributeName . '] data. Maybe you miss the scope or locale for this attribute.[' . json_encode($attribute, JSON_THROW_ON_ERROR) . ']');
            }
        }

        return $product;
    }

    protected function buildMuralsGraduatedPriceValue(
        string $step1Price,
        string $step5Price,
        string $step10Price,
        string $drillHolePrice,
        string $coatingPrice
    ): string {
        return '{"type":"price","steps":[{"quantity_start":1,"quantity_end":4,"price":' . $step1Price . '},{"quantity_start":5,"quantity_end":9,"price":' . $step5Price . '},{"quantity_start":10,"quantity_end":"*","price":' . $step10Price . '}],"adjustments":[{"amount":' . $drillHolePrice . ',"type":"drill_hole"},{"amount":' . $coatingPrice . ',"type":"coating"}]}';
    }

    protected function buildMuralsFormFieldMappingValue(
        array $size,
        string $printessMaterialValue,
        string $designOrientation
    ): string {
        $printessDocumentSizeValue = $size['width'] / 10 . 'x' . $size['height'] / 10;

        return '[{"printess_ff_name": "DOCUMENT_SIZE", "pim_attr_name": "murals_din_formats", "value": "' . $printessDocumentSizeValue . '"},{"printess_ff_name": "material", "pim_attr_name": "signs_material", "value": "' . $printessMaterialValue . '"}, {"printess_ff_name":"designOrientation","pim_attr_name":"orientation","value":"' . $designOrientation . '"}]';
    }

    protected function numberFormatPrintAreaValue(int $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    /**
     * @throws JsonException
     */
    protected function getDesignOrientationBySize(array $size, $asPimValue = false): string
    {
        $factor = $size['width'] / $size['height'];

        if (1.0 === (float) $factor) {
            return 'square';
        }

        if (($factor >= 2 && $asPimValue === true) || ($factor <= 0.5 && $asPimValue === true)) {
            return 'panorama';
        }

        if ($factor > 1) {
            if (true === $asPimValue) {
                return 'landscape';
            }

            return 'din_l';
        }

        if ($factor < 1) {
            if (true === $asPimValue) {
                return 'portrait';
            }

            return 'din_p';
        }

        $size['factor'] = $factor;

        throw new RuntimeException("Can't calculate designOrientation for size -> " . json_encode($size, JSON_THROW_ON_ERROR));
    }

    protected function getProductPrice(array $priceAttributes, array $size, $step = 1): string
    {
        $bulkSurcharge = 0;
        if ($size['width'] >= 9000 || $size['height'] >= 9000) {
            $bulkSurcharge = $priceAttributes['bulkSurcharge'];
        }

        $result = ($size['width'] / 1000 * $size['height'] / 1000 * ($priceAttributes['packagingPerSqrm'] + $priceAttributes['printingPerSqrm']) + $priceAttributes['handlingPerPiece'] + $bulkSurcharge) / $priceAttributes['margin'] * self::SHOP_TAX;

        $discount = 0;
        if (5 === $step) {
            $discount = 0.05;
        }

        if (10 === $step) {
            $discount = 0.10;
        }

        $result -= $discount * $result;

        $result = ((int) ($result * 10) + 1) / 10;

        // return (ceil($result - 0.05) - 0.1) . '';
        return $result . '';
    }

    protected function runUpsert(array $products, array $resultInfo, bool $force, bool $isModel = false): void
    {
        $this->output->writeln('Products to be updated: ' . count($resultInfo));
        foreach ($resultInfo as $uuid => $product) {
            $this->output->writeln($product['name'] . ' :: ' . $product['material'] . ' :: ' . $product['size'] . '    [' . $uuid . '] - [' . $product['supplierSku'] . ']');
        }

        if (true === $force) {
            $this->output->writeln('Start updating/write products to PIM.');

            foreach (array_chunk($products, 100) as $productsChunk) {
                try {

                    if ($isModel) {
                        $responseLines = $this->pimClient->getProductModelApi()->upsertList($productsChunk);
                    } else {
                        $responseLines = $this->pimClient->getProductApi()->upsertList($productsChunk);
                    }

                    $this->output->writeln('Written products: ' . iterator_count($responseLines));

                    foreach ($responseLines as $line) {
                        $this->output->writeln('[' . $line['status_code'] . '] ' . $line['identifier']);

                        if (422 === $line['status_code']) {
                            $this->output->writeln('<error>' . json_encode($line, JSON_THROW_ON_ERROR) . '</error>');
                        }
                    }
                } catch (Exception $ex) {
                    $this->output->writeln('<error>' . $ex->getMessage() . '</error>');
                }
            }
        }
    }

    protected function runModelUpsert(array $products, array $resultInfo, bool $force): void
    {
        $this->runUpsert($products, $resultInfo, $force, true);
    }

    protected function guessFlipSkuByMaterialAndSize(string $materialId, array $size): ?string
    {
        if (! isset($this->flipSkus[$materialId])) {
            throw new RuntimeException('The flip array key [' . $materialId . '] could not be found in flip.json. Maybe you dont load the flip.json file in the constructor.');
        }

        $flipNum = array_filter($this->flipSkus[$materialId], static function ($item) use ($size) {
            if ($item['widthMm'] === $size['width'] && $item['heightMm'] === $size['height']) {
                return $item;
            }

            if ($item['heightMm'] === $size['width'] && $item['widthMm'] === $size['height']) {
                return $item;
            }

            return null;
        });

        if (empty($flipNum)) {
            return null;
        }

        return $flipNum[array_key_first($flipNum)]['flipNum'];
    }
}
