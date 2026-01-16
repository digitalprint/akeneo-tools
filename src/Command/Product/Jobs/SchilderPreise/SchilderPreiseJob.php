<?php

/** @noinspection DuplicatedCode */

namespace App\Command\Product\Jobs\SchilderPreise;

use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use App\Command\Product\Jobs\AbstractJob;
use JetBrains\PhpStorm\NoReturn;
use JsonException;
use Symfony\Component\Console\Output\Output;

class SchilderPreiseJob extends AbstractJob
{
    protected const DEFAULT_LOCALE = 'de_DE';
    protected const DEFAULT_SCOPE = 'printplanet';
    protected const BULKY_GOODS_SIZE_MM = 1000;

    private array $materialTypes = [
        'alu-dibond-white-3mm' => [],
        'alu-dibond-silver-3mm' => [],
        'anti-grafitti-alu-dibond-white-3mm' => [],
        'pvc-5mm' => [],
        'pvc-10mm' => [],
        'acryl-5mm' => [],
        'acryl-8mm' => [],
        'metal-sign' => [],
        'metal-poster' => [],
        'magnetic-foil' => [],
        'sticker' => [],
        'car-sticker' => [],
        'wood-indoor-10mm' => [],
        'wood-indoor-15mm' => [],
    ];

    protected array $materials = [
        "freestyle-schilder" => [],
        "firmenschilder" => [],
        "praxisschilder" => [],
        "funschilder" => [],
        "funschilder-konturgeschnitten" => [],
        "warnschilder" => [],
        "parkschilder" => [],
        "parkschilder-schmal" => [],
        "pfeilwegweiser" => [],
        "richtungsschilder" => [],
        "richtungsschilder-schmal" => [],
        "ortsschilder" => [],
        "ortsschilder-schmal" => [],
        "strassenschilder" => [],
        "hundeschilder" => [],
        "geburtstagsschilder" => [],
        "geburtstagsschilder-konturgeschnitten" => [],
        "hausnummernschilder" => [],
        "blechschilder" => [],
        "blechposter" => [],

//         "holzschilder" => [],
    ];

    /**
     * @throws JsonException
     */
    public function __construct(Output $output, AkeneoPimClientInterface $pimClient)
    {
        parent::__construct($output, $pimClient);

        foreach ($this->materialTypes as $key => $attributes) {
            $attributesFile = __DIR__ . '/attributes/' . $key . '.json';
            $this->materialTypes[$key] = json_decode(file_get_contents($attributesFile), true, 512, JSON_THROW_ON_ERROR);
        }

        foreach ($this->materials as $key => $material) {
            $materialFile = __DIR__ . '/materials/' . $key . '.json';
            $this->materials[$key] = json_decode(file_get_contents($materialFile), true, 512, JSON_THROW_ON_ERROR);
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
        $products = [
            "product" => [
                "items" => [],
                "resultInfo" => []
            ],
            "model" => [
                "items" => [],
                "resultInfo" => []
            ],
            "manually" => []
        ];

        $fixedTypes = ['metal-sign', 'metal-poster'];

        foreach ($this->materials as $key => $material) {

            foreach ($material['items'] as $parentUuid => $type) {

                $products[$material['types']]["resultInfo"][$parentUuid] = [
                    'name' => $key,
                    'material' => $type,
                ];

                if ($material['price'] === 'fix') {
                    if ($material['types'] === "product") {
                        $childProducts = $this->getChildProductsByUuid($parentUuid, self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);
                    } else {
                        $childProducts = [
                            $this->getProductModelByCode($parentUuid, self::DEFAULT_SCOPE, self::DEFAULT_LOCALE)
                        ];
                    }

                    foreach ($childProducts as $childProduct) {

                        $width = (int)$childProduct['values']['printarea_width'][0]['data']['amount'];
                        $height = (int)$childProduct['values']['printarea_height'][0]['data']['amount'];

                        $basePrice = 0;
                        $data = [];

                        if (in_array($type, $fixedTypes, true)) {

                            foreach ($this->materialTypes[$type] as $item) {
                                if (
                                    ($item['width'] === $width && $item['height'] === $height) ||
                                    ($item['width'] === $height && $item['height'] === $width)
                                ) {
                                    $basePrice = $item['graduated_price']['steps'][0]['price'];
                                    $data = $item['graduated_price'];
                                }
                            }

                        } else {

                            $attributes = $this->materialTypes[$type]['formula']['attributes'];

                            $data = [
                                "type" => "price",
                                "steps" => [],
                                "adjustments" => $this->materialTypes[$type]['adjustments'] ?? [],
                            ];

                            foreach ($this->materialTypes[$type]['steps'] as $step) {
                                $data['steps'][] = [
                                    'quantity_start' => $step['quantity_start'],
                                    'quantity_end' => $step['quantity_end'],
                                    'price' => $this->calcFormula($width, $height, $attributes, $step['percentage']),
                                ];
                            }

                            $basePrice = $this->calcFormula($width, $height, $attributes);
                        }

                        $product = $this->setAttributeValueInProduct($childProduct, 'graduated_price', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);
                        $product = $this->setAttributeValueInProduct($product, 'base_price', $basePrice, self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);

                        $products[$material['types']]["items"][] = $product;
                    }
                }

                if ($material['price'] === 'formula') {

                    if (in_array($type, $fixedTypes, true)) {
                        continue;
                    }

                    $product = $this->getProductsByUuid($parentUuid, self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);

                    $data = $this->materialTypes[$type];

                    $products[$material['types']]["items"][] = $this->setAttributeValueInProduct($product, 'graduated_price', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);

                }

            }

            if ($material['price'] === 'manually') {
                $products['manually'][$key] = $material['rootProduct'];
            }

        }

        if (count($products['product']['items'])) {
            $this->runUpsert($products['product']['items'], $products['product']["resultInfo"], $force);
        }

        if (count($products['model']['items'])) {
            $this->runModelUpsert($products['model']['items'], $products['model']["resultInfo"], $force);
        }

        $commands = [];

        foreach ($this->materials as $item) {
            if ($item['price'] !== 'manually') {
                $commands[] = '/usr/bin/php7.4 shop/current/bin/console pim:import:products -I -vvv --only-product=' . $item['rootProduct'];
            }
        }

        $this->output->writeln("");

        foreach ($products['manually'] as $key => $productUuid) {
            $this->output->writeln("Das Product '" . $key . "' (" . $productUuid . ") muss manuell bearbeitet werden.");
        }

        $this->output->writeln("");
        $this->output->writeln(implode(" && ", $commands));
    }

    private function calcFormula(float $width, float $height, $attributes, float $discount = 0) : float
    {
        $attr = [
            "packagingPerSqrm" => $this->getValueById($attributes, 'packagingPerSqrm'),
            "printingPerSqrm" => $this->getValueById($attributes, 'printingPerSqrm'),
            "handlingPerPiece" => $this->getValueById($attributes, 'handlingPerPiece'),
            "margin" => $this->getValueById($attributes, 'margin'),
            "marketingDiscountThreshold" => $this->getValueById($attributes, 'marketingDiscountThreshold'),
            "bulkyGoodsSurcharge" => $this->getValueById($attributes, 'bulkyGoodsSurcharge'),
        ];

        if (($width / 10) * ($height / 10) > 150) {
            $attr['marketingDiscountThreshold'] = 0;
        }

        $price = (($width  / 1000) * ($height  / 1000) * ($attr['packagingPerSqrm'] + $attr['printingPerSqrm']) + $attr['handlingPerPiece'] + $attr['marketingDiscountThreshold']) / $attr['margin'] * self::SHOP_TAX;

        // Staffelrabatt
        $price -= ($price * $discount);

        // Abrunden auf eine Stelle hinterm Komma
        $price = floor($price * 10) / 10;

        // Sperrgut-Zuschlag
        if ($width > self::BULKY_GOODS_SIZE_MM || $height > self::BULKY_GOODS_SIZE_MM) {
            $price += $attr['bulkyGoodsSurcharge'];
        }

        return $price;
    }


    private function getValueById(array $data, string $id) {
        foreach ($data as $item) {
            if (isset($item['id']) && $item['id'] === $id) {
                return $item['value'];
            }
        }
        return null;
    }

}
