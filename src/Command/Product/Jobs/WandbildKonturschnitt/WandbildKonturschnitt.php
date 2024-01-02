<?php

/** @noinspection DuplicatedCode */

namespace App\Command\Product\Jobs\WandbildKonturschnitt;

use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use App\Command\Product\Jobs\AbstractJob;
use JetBrains\PhpStorm\NoReturn;
use JsonException;
use Symfony\Component\Console\Output\Output;

class WandbildKonturschnitt extends AbstractJob
{
    protected array $materials = [
        '28d398aa-bc2f-46d8-939b-6ba305ce22bb' => [
            'label' => 'alu-dibond-weiss',
            'printess_value' => 'alu-dibond-weiss',
            'price' => [
                'handlingPerPiece' => 5.0,
                'packagingPerSqrm' => 20.0,
                'printingPerSqrm' => 75.41,
                'margin' => 0.6,
                'bulkSurcharge' => 20.0,
            ],
        ],
        'ba63765a-15d3-407a-a3fc-d0ab3c9639be' => [
            'label' => 'alu-dibond-silber',
            'printess_value' => 'alu-dibond-silber',
            'price' => [
                'handlingPerPiece' => 7.0,
                'packagingPerSqrm' => 20.0,
                'printingPerSqrm' => 93.77,
                'margin' => 0.4,
                'bulkSurcharge' => 20.0,
            ],
        ],
        'c0a32118-7c89-4cd1-b3b5-fcf078c8484e' => [
            'label' => 'kunststoff5',
            'printess_value' => 'kunststoff5',
            'price' => [
                'handlingPerPiece' => 5.0,
                'packagingPerSqrm' => 20.0,
                'printingPerSqrm' => 46.69,
                'margin' => 0.6,
                'bulkSurcharge' => 20.0,
            ],
        ],
        '33ed9337-4fcd-43ea-b760-2b43aa25be74' => [
            'label' => 'kunststoff10',
            'printess_value' => 'kunststoff10',
            'price' => [
                'handlingPerPiece' => 5.0,
                'packagingPerSqrm' => 20.0,
                'printingPerSqrm' => 63.08,
                'margin' => 0.6,
                'bulkSurcharge' => 20.0,
            ],
        ],
        '3d1c313c-735c-40a3-a68c-41a8eabe4a11' => [
            'label' => 'acrylglas3',
            'printess_value' => 'acrylglas3',
            'price' => [
                'handlingPerPiece' => 5.0,
                'packagingPerSqrm' => 20.0,
                'printingPerSqrm' => 81.87,
                'margin' => 0.6,
                'bulkSurcharge' => 20.0,
            ],
        ],
        'a91016cc-7829-492d-8b9a-d7de80f07d5e' => [
            'label' => 'acrylglas5',
            'printess_value' => 'acrylglas5',
            'price' => [
                'handlingPerPiece' => 5.0,
                'packagingPerSqrm' => 20.0,
                'printingPerSqrm' => 104.80,
                'margin' => 0.6,
                'bulkSurcharge' => 20.0,
            ],
        ],
        '04910363-5424-4050-90c4-5e3cda6dea4b' => [
            'label' => 'acrylglas8',
            'printess_value' => 'acrylglas8',
            'price' => [
                'handlingPerPiece' => 5.0,
                'packagingPerSqrm' => 20.0,
                'printingPerSqrm' => 137.74,
                'margin' => 0.6,
                'bulkSurcharge' => 20.0,
            ],
        ],
        '70c08f83-7792-484b-86d4-7be32076e6bc' => [
            'label' => 'magnetic_foil',
            'printess_value' => 'magnetfolie',
            'price' => [
                'handlingPerPiece' => 10.0,
                'packagingPerSqrm' => 20.0,
                'printingPerSqrm' => 67.48,
                'margin' => 0.6,
                'bulkSurcharge' => 0.0,
            ],
        ],
        '86784836-c55f-4692-bcee-c33046901fe3' => [
            'label' => 'wood_10mm',
            'printess_value' => 'wood_10mm',
            'price' => [
                'handlingPerPiece' => 5.0,
                'packagingPerSqrm' => 20.0,
                'printingPerSqrm' => 83.85,
                'margin' => 0.5,
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
        if (($handle = fopen(__DIR__ . '/flip.csv', 'rb')) !== false) {
            $result = ['weiss' => [], 'silber' => []];
            while (($data = fgetcsv($handle, null, ';')) !== false) {
                $chunks = explode('|', $data[1]);
                $chinks = explode('x', $chunks[0]);

                if (isset($chinks[0])) {
                    $chinks[0] = trim($chinks[0]);
                }

                if (isset($chinks[1])) {
                    $chinks[1] = trim($chinks[1]);
                }

                if (! isset($chinks[1])) {
                    $chinks[0] = $data[2] / 10;
                    $chinks[1] = $data[3] / 10;
                } else {
                    $chinks[0] /= 10;
                    $chinks[1] /= 10;
                }

                $data[2] *= 1;
                $data[3] *= 1;

                if (0 === $data[2]) {
                    $data[2] = $chinks[0] * 10;
                }

                if (0 === $data[3]) {
                    $data[3] = $chinks[1] * 10;
                }

                $row = [
                    'flipNum' => $data[0],
                    'color' => trim($chunks[1]),
                    'widthCm' => $chinks[0],
                    'heightCm' => $chinks[1],
                    'widthMm' => $data[2] * 1,
                    'heightMm' => $data[3] * 1,
                ];

                $result[$row['color']][] = $row;
            }
            fclose($handle);

            file_put_contents(__DIR__ . '/flip.json', json_encode($result, JSON_THROW_ON_ERROR));
        }

        exit('json written');
    }
}
