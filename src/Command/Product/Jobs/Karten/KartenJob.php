<?php

/** @noinspection DuplicatedCode */

namespace App\Command\Product\Jobs\Karten;

use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use App\Command\Product\Jobs\AbstractJob;
use JetBrains\PhpStorm\NoReturn;
use JsonException;
use Symfony\Component\Console\Output\Output;

class KartenJob extends AbstractJob
{
    protected const DEFAULT_LOCALE = 'de_DE';
    protected const DEFAULT_SCOPE = 'printplanet';
    private array $config ;

    /**
     * @param Output $output
     * @param AkeneoPimClientInterface $pimClient
     * @throws JsonException
     */
    public function __construct(Output $output, AkeneoPimClientInterface $pimClient)
    {
        parent::__construct($output, $pimClient);

        $this->config = [
            'materials' => []
        ];

        $importFile = __DIR__ . '/materials.json';
        $this->config['materials'] = json_decode(file_get_contents($importFile), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param bool $force
     *
     * @throws JsonException
     */
    #[NoReturn]
    public function execute(bool $force = false): void
    {
        $parents = [];
        $products = [];
        $resultInfo = [];

        foreach ($this->config['materials'] as $Uuid) {

            $product = $this->getProductsByUuid($Uuid, self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);
            $graduated_price = json_decode($product['values']['graduated_price'][0]['data'], true);


            if (isset($graduated_price['adjustments'])) {
                foreach ($graduated_price['adjustments'] as $key => $adjustment) {
                    if ($adjustment['id'] === 'invercote_g_240g') {
                        unset($graduated_price['adjustments'][$key]);
                    }
                }
            }

            $product = $this->setAttributeValueInProduct($product, 'graduated_price', json_encode($graduated_price, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);

            $short_description = $product['values']['short_description'][0]['data'] ?? null;

            if ($short_description) {
                $short_description = str_replace("weiß mattes Papier 240", "weiß mattes Papier 300", $short_description);
                $product = $this->setAttributeValueInProduct($product, 'short_description', $short_description, self::DEFAULT_SCOPE, self::DEFAULT_LOCALE);
            }

            $parents[] = $product['parent'];
            $products[] = $product;

            $resultInfo[$product['identifier']] = [
                'name' => $product['values']['name'][0]['data'] ?? '...'
            ];
        }




        $this->runUpsert($products, $resultInfo, $force);

        $commands = [];

        foreach (array_unique($parents) as $Uuid) {
            $commands[] = '/usr/bin/php7.4 /nfs/printplanet-pk1/shop/www/shop/current/bin/console pim:import:products -I -vvv --only-product=' . $Uuid;
        }

        $this->output->writeln("");
        $this->output->writeln(implode(" && ", $commands));
    }
}