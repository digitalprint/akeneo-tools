<?php

/** @noinspection DuplicatedCode */

namespace App\Command\Product\Jobs\MrFotoPoster;

use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use App\Command\Product\Jobs\AbstractJob;
use JetBrains\PhpStorm\NoReturn;
use JsonException;
use Symfony\Component\Console\Output\Output;

class MrFotoPosterJob extends AbstractJob
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
//        $locale = self::DEFAULT_LOCALE;
        $locale = 'en_US';

        $products = [];
        $resultInfo = [];
        foreach ($this->materials as $parentUuid => $material) {
            $childProducts = $this->getChildProductsByUuid($parentUuid);

            foreach ($childProducts as $product) {
                $size = [
                    'width' => $product['values']['printarea_width'][0]['data']['amount'],
                    'height' => $product['values']['printarea_height'][0]['data']['amount'],
                ];

                $designOrientation = $this->getDesignOrientationBySize($size);

//                $product = $this->setAttributeValueInProduct($product, 'is_in_stock', false, self::DEFAULT_SCOPE, $locale);
//                $product = $this->setAttributeValueInProduct($product, 'active', true, self::DEFAULT_SCOPE, $locale);
//                $product = $this->setAttributeValueInProduct($product, 'base_price', 10.9, self::DEFAULT_SCOPE, $locale);

                $documentSize = $this->getPrintessDocumentSizeBySize($size);




                $materialPrintessValue = $material['printess_value'];
                $mockupFormField = <<<JSON
[
  {"ff_name": "material",          "ff_value": "$materialPrintessValue" },
  {"ff_name": "designOrientation", "ff_value": "$designOrientation" },
  {"ff_name": "DOCUMENT_SIZE",     "ff_value": "$documentSize"  },
  {"ff_name": "edges",             "ff_value": "angular"}
]
JSON;
                $product = $this->setAttributeValueInProduct($product, 'mockup_form_fields', $mockupFormField, self::DEFAULT_SCOPE, $locale);




                $width = (int) $size['width'];
                $height = (int) $size['height'];
                $printPlacements = <<<JSON
{
  "placements": [
    {
      "id": "main",
      "width": $width,
      "height": $height,
      "section": {"top": 3, "right": 3, "bottom": 3, "left": 3}
    }
  ]
}
JSON;
                $product = $this->setAttributeValueInProduct($product, 'print_placements', $printPlacements, self::DEFAULT_SCOPE);




                $supplierProductionParameters = <<<JSON
{
  "produceType": "printess",
  "context": {
    "templateName": "photo_poster_poster",
    "mergeTemplates": [
      {"templateName": "ls_basic", "documentName": "din_l"}
    ],
    "formField": {
      "mapping": [
        {
          "printess_form_field_name"  : "image_1",
          "merchrocket_placement_type": "main"
        }
      ],
      "values": [
        {
          "printess_form_field_name" : "DOCUMENT_SIZE",
          "printess_form_field_value": "$documentSize"
        },
        {
          "printess_form_field_name" : "material",
          "printess_form_field_value": "$materialPrintessValue"
        },
        {
          "printess_form_field_name" : "designOrientation",
          "printess_form_field_value": "$designOrientation"
        },
        {
          "printess_form_field_name" : "edges",
          "printess_form_field_value": "angular"
        }
      ]
    }
  }
}
JSON;
                $product = $this->setAttributeValueInProduct($product, 'supplier_production_parameters', $supplierProductionParameters, self::DEFAULT_SCOPE);




                $products[] = $product;

                $resultInfo[$product['identifier']] = [
                    'material' => $material['printess_value'],
                    'size' => $width . 'x' . $height,
                ];
            }
        }

        $this->runUpsert($products, $resultInfo, $force);
    }

    private function getPrintessDocumentSizeBySize(array $sizes): string
    {
        $sizes = array_map(static function ($size) {
            return $size / 10;
        }, $sizes);

        if ($sizes['width'] === 14.8) {
            $sizes['width'] = 14.85;
        }

        if ($sizes['height'] === 14.8) {
            $sizes['height'] = 14.85;
        }

        return $sizes['width'] . 'x' . $sizes['height'];
    }
}
