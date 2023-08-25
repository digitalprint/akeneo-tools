<?php

/** @noinspection DuplicatedCode */

namespace App\Command\Product\Jobs\FotoPoster;

use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use App\Command\Product\Jobs\AbstractJob;
use JetBrains\PhpStorm\NoReturn;
use JsonException;
use Symfony\Component\Console\Output\Output;

class FotoPosterJob extends AbstractJob
{
    protected array $materials = [
        '42f432bf-4d14-4cb5-917a-36feeacaf899' => [
            'label' => 'premium_bilderdruck',
            'printess_value' => 'poster',
            'price' => [
                'handlingPerPiece' => 3.0,
                'packagingPerSqrm' => 5.0,
                'printingPerSqrm' => 20.31,
                'margin' => 0.7,
                'bulkSurcharge' => 0.0,
            ],
        ],
        'e058f29a-af05-4de6-84b2-a786086f6a59' => [
            'label' => 'kunststoff_poster',
            'printess_value' => 'plastic-poster',
            'price' => [
                'handlingPerPiece' => 5.0,
                'packagingPerSqrm' => 5.0,
                'printingPerSqrm' => 20.36,
                'margin' => 0.7,
                'bulkSurcharge' => 0.0,
            ],
        ],
    ];

    protected string $productFamily = 'murals';

    /**
     * @throws JsonException
     */
    public function __construct(Output $output, AkeneoPimClientInterface $pimClient)
    {
        parent::__construct($output, $pimClient);

        $attributesFile = __DIR__ . '/attributes.json';
        if (is_file($attributesFile)) {
            $this->possibleAttributes = json_decode(file_get_contents($attributesFile), true, 512, JSON_THROW_ON_ERROR);
        }

        $flipSkusFile = __DIR__ . '/flip.json';
        if (is_file($flipSkusFile)) {
            $this->flipSkus = json_decode(file_get_contents($flipSkusFile), true, 512, JSON_THROW_ON_ERROR);
        }
    }

    /**
     * @param bool $force
     *
     * @throws JsonException
     */
    #[NoReturn]
    public function execute(bool $force = false): void
    {
//        $this->convertFlipNums();

        $products = [];
        $resultInfo = [];

        $sizes = json_decode(file_get_contents(__DIR__ . '/sizes.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach ($this->materials as $parentUuid => $material) {
            $childProducts = $this->getChildProductsByUuid($parentUuid);

            foreach ($sizes as $sizePimAttributeValue => $size) {
                $pimProduct = $this->findProductInProductsByAttributeAndValue($childProducts, 'murals_din_formats', $sizePimAttributeValue);

                if (! empty($pimProduct)) {
                    $product = $pimProduct[array_key_first($pimProduct)];
                } else {
                    $product = $this->getProductVariantSkeleton($this->productFamily, $parentUuid, null, 'category_pp');
                    $product = $this->setAttributeValueInProduct($product, 'murals_din_formats', $sizePimAttributeValue);
                }

                $supplierSku = $this->guessFlipSkuByMaterialAndSize($material['label'], $size);

                if (null === $supplierSku) {
                    $this->output->writeln('<error>Cant get the flip sku for variant ' . $material['label'] . ' in size ' . $sizePimAttributeValue . ' </error>');

                    $supplierSku = 'XXYYZZ';
                }

                $product = $this->setAttributeValueInProduct($product, 'supplier_sku', $supplierSku);
                $product = $this->setAttributeValueInProduct($product, 'is_in_stock', true, self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);
                $product = $this->setAttributeValueInProduct($product, 'stock_quantity', 5000);
                $product = $this->setAttributeValueInProduct($product, 'active', true, self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);
                $product = $this->setAttributeValueInProduct($product, 'base_price', $this->getProductPrice($material['price'], $size), self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);
                $product = $this->setAttributeValueInProduct($product, 'graduated_price', $this->buildMuralsGraduatedPriceValue($this->getProductPrice($material['price'], $size), $this->getProductPrice($material['price'], $size, 5), $this->getProductPrice($material['price'], $size, 10), '3.0', '3.5'), self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);
                $product = $this->setAttributeValueInProduct($product, 'printarea_width', $this->numberFormatPrintAreaValue($size['width']));
                $product = $this->setAttributeValueInProduct($product, 'printarea_height', $this->numberFormatPrintAreaValue($size['height']));
                $product = $this->setAttributeValueInProduct($product, 'printarea_section_variable', '3');
                $product = $this->setAttributeValueInProduct($product, 'dpi', 200);
                $product = $this->setAttributeValueInProduct($product, 'cpp_start_design_id', $this->buildMuralsFormFieldMappingValue($size, $material['printess_value'], $this->getDesignOrientationBySize($size)), self::DEFAULT_SCOPE);
                $product = $this->setAttributeValueInProduct($product, 'orientation', $this->getDesignOrientationBySize($size, true), self::DEFAULT_SCOPE);

                $products[] = $product;

                $resultInfo[$product['identifier']] = [
                    'material' => $material['printess_value'],
                    'size' => $sizePimAttributeValue,
                    'supplierSku' => $supplierSku,
                ];
            }
        }

        $this->runUpsert($products, $resultInfo, $force);
    }

    /**
     * @throws JsonException
     */
    #[NoReturn]
    private function convertFlipNums(): void
    {
        if (($handle = fopen(__DIR__ . '/arbeitsmappe_postershop_2023.csv', 'rb')) !== false) {
            $result = ['premium_bilderdruck' => [], 'kunststoff_poster' => []];

            $index = [
                'premium_bilderdruck' => 11,
                'kunststoff_poster' => 12,
            ];

            $rowCount = 0;
            while (($row = fgetcsv($handle, null, ';')) !== false) {
                ++$rowCount;

                if ($rowCount <= 3) {
                    continue;
                }

                if (empty($row[1])) {
                    continue;
                }

                foreach ($index as $identifier => $num) {

                    $data = [
                        'flipNum' => $row[$num],
                        'thickness' => $identifier,
                        'widthCm' => (int) $row[1] / 10,
                        'heightCm' => (int) $row[2] / 10,
                        'widthMm' => (int) $row[1],
                        'heightMm' => (int) $row[2],
                    ];

                    $result[$data['thickness']][] = $data;

                    $data = [
                        'flipNum' => $row[$num],
                        'thickness' => $identifier,
                        'widthCm' => (int) $row[2] / 10,
                        'heightCm' => (int) $row[1] / 10,
                        'widthMm' => (int) $row[2],
                        'heightMm' => (int) $row[1],
                    ];

                    $result[$data['thickness']][] = $data;
                }

            }

            fclose($handle);

            file_put_contents(__DIR__ . '/flip.json', json_encode($result, JSON_THROW_ON_ERROR));
        }

        exit('json written');
    }
}
