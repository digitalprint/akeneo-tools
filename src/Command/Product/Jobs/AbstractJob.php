<?php

/** @noinspection CallableParameterUseCaseInTypeContextInspection */

namespace App\Command\Product\Jobs;

use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use Akeneo\Pim\ApiClient\Search\SearchBuilder;
use Illuminate\Support\Arr;
use JsonException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\Console\Output\Output;

class AbstractJob implements JobInterface
{
    protected const SHOP_TAX = 1.19;

    protected const DEFAULT_LOCALE = 'de_DE';

    protected const DEFAULT_SCOPE = 'printplanet';

    protected array $resultInfo;

    /**
     * @var Output
     */
    protected Output $output;

    protected AkeneoPimClientInterface $pimClient;

    protected array $possibleAttributes = [];

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

    protected function getChildProductsByUuid(string $parentUuid): array
    {
        $searchBuilder = new SearchBuilder();
        $searchBuilder
            ->addFilter('parent', 'IN', [$parentUuid]);
        $searchFilters = $searchBuilder->getFilters();

        $cursor = $this->pimClient->getProductApi()->all(50, ['search' => $searchFilters, 'scope' => 'printplanet']);

        return iterator_to_array($cursor);
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

    protected function buildSignsGraduatedPriceValue(
        string $step1Price,
        string $step5Price,
        string $step10Price,
        string $drillHolePrice,
        string $coatingPrice
    ): string {
        return '{"type":"price","steps":[{"quantity_start":1,"quantity_end":4,"price":' . $step1Price . '},{"quantity_start":5,"quantity_end":9,"price":' . $step5Price . '},{"quantity_start":10,"quantity_end":"*","price":' . $step10Price . '}],"adjustments":[{"amount":' . $drillHolePrice . ',"type":"drill_hole"},{"amount":' . $coatingPrice . ',"type":"coating"}]}';
    }

    protected function buildSignsFormFieldMappingValue(
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
    protected function getDesignOrientationBySize(array $size): string
    {
        $factor = $size['width'] / $size['height'];

        if (1 === $factor) {
            return 'square';
        }

        if ($factor > 1) {
            return 'din_l';
        }

        if ($factor < 1) {
            return 'din_p';
        }

        throw new RuntimeException("Can't calculate designOrientation for size -> " . json_encode($size, JSON_THROW_ON_ERROR));
    }

    protected function getProductPrice(array $priceAttributes, array $size, $step = 1): string
    {
        $bulkSurcharge = 0;
        if ($size['width'] >= 9000 || $size['height'] >= 9000) {
            $bulkSurcharge = $priceAttributes['bulkSurcharge'];
        }

        $price = ($size['width'] / 1000 * $size['height'] / 1000 * ($priceAttributes['packagingPerSqrm'] + $priceAttributes['printingPerSqrm']) + $priceAttributes['handlingPerPiece'] + $bulkSurcharge) / $priceAttributes['margin'] * self::SHOP_TAX;

        $discount = 0;
        if (5 === $step) {
            $discount = 0.05;
        }

        if (10 === $step) {
            $discount = 0.10;
        }

        $price -= $discount * $price;

        return (ceil($price - 0.05) - 0.1) . '';
    }
}
