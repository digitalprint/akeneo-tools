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
    private array $config;

    private array $materialTypes = [
        'alu-dibond-white-3mm' => [],
        'alu-dibond-silver-3mm' => [],
        'alu-dibond-gold-3mm' => [],
        'anti-grafitti-alu-dibond-white-3mm' => [],
        'pvc-5mm' => [],
        'pvc-10mm' => [],
        'acryl-5mm' => [],
        'acryl-8mm' => [],
        'metal-sign' => [],
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

////        "holzschilder" => [],
    ];

    /**
     * @throws JsonException
     */
    public function __construct(Output $output, AkeneoPimClientInterface $pimClient)
    {
        parent::__construct($output, $pimClient);

        $importFile = __DIR__ . '/config/preiskonfigurator-config.json';
        $import = json_decode(file_get_contents($importFile), true, 512, JSON_THROW_ON_ERROR);

        $mappingFile = __DIR__ . '/config/mapping.json';
        $mapping = json_decode(file_get_contents($mappingFile), true, 512, JSON_THROW_ON_ERROR);

        $import["materials"] = $this->materialsMapping($import, $mapping);

        $this->config = $import;

        foreach ($this->materialTypes as $key => $attributes) {
            $config = $this->config['materials'][$key] ?? null;

            if ($config) {
                $attributesFile = __DIR__ . '/attributes/' . $key . '.json';
                $this->materialTypes[$key] = json_decode(file_get_contents($attributesFile), true, 512, JSON_THROW_ON_ERROR);
                $config = $this->config['materials'][$key];
            }
        }

        foreach ($this->materials as $key => $material) {
            $materialFile = __DIR__ . '/materials/' . $key . '.json';
            $this->materials[$key] = json_decode(file_get_contents($materialFile), true, 512, JSON_THROW_ON_ERROR);
        }
    }

    private function materialsMapping(array $import, array $mapping): array
    {
        $materials = [];

        foreach ($mapping['materials'] as $id => $value) {
            $key = array_search($value['key'], array_column($import['materials'], 'name'));
            $materials[$id] = ($key !== false) ? $import['materials'][$key] : null; ;
        }

        return $materials;
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

        $fixedTypes = ['metal-sign'];

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

                            $data = [
                                "type" => "price",
                                "steps" => [],
                                "adjustments" => $this->getAdjustments($type, $width, $height),
                            ];

                            $mat = $this->config['materials'][$type];
                            $result = $this->calc($mat, $width, $height, $this->config);

                            $rabatte = $this->config['rabatte'];
                            $counts = array_keys($rabatte);

                            for ($i = 0; $i < count($counts); $i++) {
                                $count = $counts[$i];

                                $nextCount = isset($counts[$i + 1]) ? ($counts[$i + 1] - 1) : '*';

                                $data['steps'][] = [
                                    'quantity_start' => $count,
                                    'quantity_end'   => $nextCount,
                                    'price'          => $this->stueckpreis($result, $count, $this->config),
                                ];
                            }

                            $basePrice = $this->stueckpreis($result, 1, $this->config);
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
                $commands[] = '/usr/bin/php7.4 /nfs/printplanet-pk1/shop/www/shop/current/bin/console pim:import:products -I -vvv --only-product=' . $item['rootProduct'];
            }
        }

        $this->output->writeln("");

        foreach ($products['manually'] as $key => $productUuid) {
            $this->output->writeln("Das Product '" . $key . "' (" . $productUuid . ") muss manuell bearbeitet werden.");
        }

        $this->output->writeln("");
        $this->output->writeln(implode(" && ", $commands));
    }

//    private function calcFormula(float $width, float $height, $attributes, float $discount = 0) : float
//    {
//        $attr = [
//            "packagingPerSqrm" => $this->getValueById($attributes, 'packagingPerSqrm'),
//            "printingPerSqrm" => $this->getValueById($attributes, 'printingPerSqrm'),
//            "handlingPerPiece" => $this->getValueById($attributes, 'handlingPerPiece'),
//            "margin" => $this->getValueById($attributes, 'margin'),
//            "marketingDiscountThreshold" => $this->getValueById($attributes, 'marketingDiscountThreshold'),
//            "bulkyGoodsSurcharge" => $this->getValueById($attributes, 'bulkyGoodsSurcharge'),
//        ];
//
//        if (($width / 10) * ($height / 10) > 150) {
//            $attr['marketingDiscountThreshold'] = 0;
//        }
//
//        $price = (($width  / 1000) * ($height  / 1000) * ($attr['packagingPerSqrm'] + $attr['printingPerSqrm']) + $attr['handlingPerPiece'] + $attr['marketingDiscountThreshold']) / $attr['margin'] * self::SHOP_TAX;
//
//        // Staffelrabatt
//        $price -= ($price * $discount);
//
//        // Abrunden auf eine Stelle hinterm Komma
//        $price = floor($price * 10) / 10;
//
//        // Sperrgut-Zuschlag
//        if ($width > self::BULKY_GOODS_SIZE_MM || $height > self::BULKY_GOODS_SIZE_MM) {
//            $price += $attr['bulkyGoodsSurcharge'];
//        }
//
//        return $price;
//    }

    private function getAdjustments(string $type, int $width, int $height): array
    {
        $mat = $this->config['materials'][$type];
        $areaSqm = ($width / 1000) * ($height / 1000);

        $adjustments = [];

        if ($mat['optBohrloch']) {
            $adjustments[] = [
                "amount" => $this->config["bohrlochPreis"],
                "type" => "drill_hole",
            ];
        }

        if ($mat['optLack']) {
            $adjustments[] = [
                "amount" => $areaSqm * $this->config["lackierungQm"],
                "type" => "coating",
            ];
        }

        if ($mat['optWeiss']) {
            $adjustments[] = [
                "amount" => $areaSqm * $this->config["weissgrundQm"],
                "type" => "white_primer",
            ];
        }

        return $adjustments;
    }

    private function calc(array $mat, float $breiteMM, float $hoeheMM, array $state): array
    {
        $flaeche = ($breiteMM * $hoeheMM) / 1e6;
        $qmPreis = $mat['druckMaterial'] + $mat['verpackung'];

        if ($flaeche < $state['hKleinGrenze']) {
            $hFaktor = $state['hKleinFaktor'] / 100;
        } elseif ($flaeche > $state['hGrossGrenze']) {
            $hFaktor = $state['hGrossFaktor'] / 100;
        } else {
            $hFaktor = 1.0;
        }

        $handlingEff = $mat['handling'] * $hFaktor;
        $stueckKosten = $flaeche * $qmPreis + $handlingEff;
        $auftragKosten = $mat['adminFlip'] + $mat['zusatz'];

        $lang = max($breiteMM, $hoeheMM);
        $kurz = min($breiteMM, $hoeheMM);
        $sperrgut = ($mat['starr'] && ($lang > $state['sperrgutLang'] || $kurz > $state['sperrgutKurz']))
            ? $state['sperrgutAufschlag']
            : 0.0;

        // Schutz vor Marge >= 100 %
        $margenDivisor = max(1 - $mat['marge'] / 100, 0.01);

        // Material-Override des Degressionsanteils, sonst globaler Wert
        $dQuelle = (isset($mat['degression']) && $mat['degression'] !== null && $mat['degression'] !== '')
            ? (float)$mat['degression']
            : $state['degressionAnteil'];
        $d = min(max($dQuelle / 100, 0), 1);

        $akBei = fn(float $n) => $auftragKosten * ($d + (1 - $d) / $n);
        $nettoBei = fn(float $n) => ($stueckKosten + $akBei($n)) / $margenDivisor;

        return [
            'flaeche' => $flaeche,
            'qmPreis' => $qmPreis,
            'hFaktor' => $hFaktor,
            'handlingEff' => $handlingEff,
            'stueckKosten' => $stueckKosten,
            'auftragKosten' => $auftragKosten,
            'sperrgut' => $sperrgut,
            'margenDivisor' => $margenDivisor,
            'd' => $d,
            'dOverride' => $dQuelle !== $state['degressionAnteil'],
            'akBei' => $akBei,
            'nettoBei' => $nettoBei,
        ];
    }

    private function floorTo(float $v, float $step): float
    {
        return floor(($v + 1e-9) / $step) * $step;
    }

    private function pretty(float $v): float
    {
        if ($v > 0 && (round($v * 100)) % 100 === 0) {
            return $v - 0.1;
        }
        return $v;
    }

    private function stueckpreis(array $r, int $n, array $state): float
    {
        $rab = ($state['rabatte'][$n] ?? 0) / 100;
        $netto = $r['nettoBei']($n) * (1 - $rab);
        $netto = max($netto, 0);
        $brutto = ($netto + $r['sperrgut']) * (1 + $state['mwst'] / 100);

        return round($this->pretty($this->floorTo($brutto, $state['rundung'])), 2);
    }

//    private function getValueById(array $data, string $id) {
//        foreach ($data as $item) {
//            if (isset($item['id']) && $item['id'] === $id) {
//                return $item['value'];
//            }
//        }
//        return null;
//    }

}
