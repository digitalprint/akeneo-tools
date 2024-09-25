<?php

/** @noinspection DuplicatedCode */

namespace App\Command\Product\Jobs\MigrateRatingIds;

use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use App\Command\Product\Jobs\AbstractJob;
use JetBrains\PhpStorm\NoReturn;
use JsonException;
use Symfony\Component\Console\Output\Output;

class MigrateRatingIdsJob extends AbstractJob
{
    public function __construct(Output $output, AkeneoPimClientInterface $pimClient)
    {
        parent::__construct($output, $pimClient);
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
        $resultInfo = [];

        foreach ($this->flipSkus['variantIds'] as $variantId) {
            $product = $this->getProductByFlipSku($variantId);

            if (null === $product) {
                $this->output->writeln('<error>Cant get product for flip sku ' . $variantId . ' </error>');

                continue;
            }

            $product = $this->setAttributeValueInProduct($product, 'stock_quantity', 0);

            $products[] = $product;

            $resultInfo[$product['identifier']] = [
                'name' => $product['name'],
                'supplierSku' => $variantId,
            ];
        }

        $this->runUpsert($products, $resultInfo, $force);
    }
}
