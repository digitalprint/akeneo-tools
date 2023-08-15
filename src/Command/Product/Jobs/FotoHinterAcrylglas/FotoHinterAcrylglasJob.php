<?php

namespace App\Command\Product\Jobs\FotoHinterAcrylglas;

use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use App\Command\Product\Jobs\AbstractJob;
use Exception;
use JetBrains\PhpStorm\NoReturn;
use JsonException;
use Symfony\Component\Console\Output\Output;

class FotoHinterAcrylglasJob extends AbstractJob
{
    protected array $materials = [
        'a167c53e-bc58-4de8-bb90-9d16a945ce6b' => [
            'label' => '3 mm',
            'printess_value' => 'acrylglas3',
            'price' => [
                'packagingPerSqrm' => 20.0,
                'printingPerSqrm' => 94,
                'handlingPerPiece' => 5.0,
                'margin' => 0.6,
                'bulkSurcharge' => 20.0,
            ],
        ],
        'bdff860c-815b-40df-a102-cd185f1ccaf5' => [
            'label' => '5 mm',
            'printess_value' => 'acrylglas5',
            'price' => [
                'packagingPerSqrm' => 20.0,
                'printingPerSqrm' => 119.76,
                'handlingPerPiece' => 5.0,
                'margin' => 0.6,
                'bulkSurcharge' => 20.0,
            ],
        ],
        '17c02a26-b6dd-45d3-85b1-76f2c1d8d20f' => [
            'label' => '8 mm',
            'printess_value' => 'acrylglas8',
            'price' => [
                'packagingPerSqrm' => 20.0,
                'printingPerSqrm' => 157.42,
                'handlingPerPiece' => 5.0,
                'margin' => 0.6,
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
    }

    /**
     * @param bool $force
     *
     * @throws JsonException
     */
    #[NoReturn]
    public function execute(bool $force = false): void
    {
        $products = [];

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

                $product = $this->setAttributeValueInProduct($product, 'supplier_sku', $size['supplier_sku']);
                $product = $this->setAttributeValueInProduct($product, 'is_in_stock', true, self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);
                $product = $this->setAttributeValueInProduct($product, 'stock_quantity', 5000);
                $product = $this->setAttributeValueInProduct($product, 'active', true, self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);
                $product = $this->setAttributeValueInProduct($product, 'base_price', $this->getProductPrice($material['price'], $size), self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);
                $product = $this->setAttributeValueInProduct($product, 'graduated_price', $this->buildSignsGraduatedPriceValue($this->getProductPrice($material['price'], $size), $this->getProductPrice($material['price'], $size, 5), $this->getProductPrice($material['price'], $size, 10), '3.0', '3.5'), self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);
                $product = $this->setAttributeValueInProduct($product, 'printarea_width', $this->numberFormatPrintAreaValue($size['width']));
                $product = $this->setAttributeValueInProduct($product, 'printarea_height', $this->numberFormatPrintAreaValue($size['height']));
                $product = $this->setAttributeValueInProduct($product, 'printarea_section_variable', '3');
                $product = $this->setAttributeValueInProduct($product, 'dpi', 300);
                $product = $this->setAttributeValueInProduct($product, 'cpp_start_design_id', $this->buildSignsFormFieldMappingValue($size, $material['printess_value'], $this->getDesignOrientationBySize($size)), self::DEFAULT_SCOPE);

                $products[] = $product;

                $this->resultInfo[$product['identifier']] = [
                    'material' => $material['printess_value'],
                    'size' => $sizePimAttributeValue,
                ];
            }
        }

        $this->output->writeln('Products to be updated: ' . count($this->resultInfo));
        foreach ($this->resultInfo as $uuid => $product) {
            $this->output->writeln($product['material'] . ' :: ' . $product['size'] . '    [' . $uuid . ']');
        }

        if (true === $force) {
            $this->output->writeln('Start updating/write products to PIM.');

            foreach (array_chunk($products, 100) as $productsChunk) {
                try {
                    $responseLines = $this->pimClient->getProductApi()->upsertList($productsChunk);

                    $this->output->writeln('Written products: ' . iterator_count($responseLines));

                    foreach ($responseLines as $line) {
                        $this->output->writeln('[' . $line['status_code'] . '] ' . $line['identifier']);

                        if (422 === $line['status_code']) {
                            $this->output->writeln('<error>' . json_encode($line['errors'], JSON_THROW_ON_ERROR) . '</error>');
                        }
                    }

                } catch (Exception $ex) {
                    $this->output->writeln('<error>' . $ex->getMessage() . '</error>');
                }
            }
        }
    }
}
