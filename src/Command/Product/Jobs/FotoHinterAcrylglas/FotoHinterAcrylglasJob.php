<?php

/** @noinspection PhpUnusedPrivateMethodInspection */

namespace App\Command\Product\Jobs\FotoHinterAcrylglas;

use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use App\Command\Product\Jobs\AbstractJob;
use JetBrains\PhpStorm\NoReturn;
use JsonException;
use Symfony\Component\Console\Output\Output;

class FotoHinterAcrylglasJob extends AbstractJob
{
    protected array $materials = [
        'a167c53e-bc58-4de8-bb90-9d16a945ce6b' => [
            'label' => '3mm',
            'printess_value' => 'acrylglas3',
            'price' => [
                'handlingPerPiece' => 5.0,
                'packagingPerSqrm' => 20.0,
                'printingPerSqrm' => 81.87,
                'margin' => 0.7,
                'bulkSurcharge' => 20.0,
            ],
        ],
        'bdff860c-815b-40df-a102-cd185f1ccaf5' => [
            'label' => '5mm',
            'printess_value' => 'acrylglas5',
            'price' => [
                'handlingPerPiece' => 5.0,
                'packagingPerSqrm' => 20.0,
                'printingPerSqrm' => 104.80,
                'margin' => 0.7,
                'bulkSurcharge' => 20.0,
            ],
        ],
        '17c02a26-b6dd-45d3-85b1-76f2c1d8d20f' => [
            'label' => '8mm',
            'printess_value' => 'acrylglas8',
            'price' => [
                'handlingPerPiece' => 5.0,
                'packagingPerSqrm' => 20.0,
                'printingPerSqrm' => 137.74,
                'margin' => 0.7,
                'bulkSurcharge' => 20.0,
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
        // $this->convertFlipNums();

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
                $product = $this->setAttributeValueInProduct($product, 'dpi', 300);
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
        if (($handle = fopen(__DIR__ . '/flip.csv', 'rb')) !== false) {
            $result = ['3mm' => [], '5mm' => [], '8mm' => []];
            while (($data = fgetcsv($handle, null, ';')) !== false) {
                $chunks = explode('|', $data[1]);
                $chinks = explode('x', $chunks[1]);

                if (! isset($chinks[1])) {
                    $chinks[0] = $data[2] / 10;
                    $chinks[1] = $data[3] / 10;
                } else {
                    $chinks[0] = trim($chinks[0]) / 10;
                    $chinks[1] = trim($chinks[1]) / 10;
                }

                $row = [
                    'flipNum' => $data[0],
                    'thickness' => trim($chunks[0]),
                    'widthCm' => $chinks[0],
                    'heightCm' => $chinks[1],
                    'widthMm' => $data[2] * 1,
                    'heightMm' => $data[3] * 1,
                ];

                $result[$row['thickness']][] = $row;
            }
            fclose($handle);

            file_put_contents(__DIR__ . '/flip.json', json_encode($result, JSON_THROW_ON_ERROR));
        }

        exit;
    }
}
