<?php

/** @noinspection DuplicatedCode */

namespace App\Command\Product\Jobs\SchilderLackierungsAufpreis;

use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use Akeneo\Pim\ApiClient\Search\SearchBuilder;
use App\Command\Product\Jobs\AbstractJob;
use JetBrains\PhpStorm\NoReturn;
use JsonException;
use Symfony\Component\Console\Output\Output;

class SchilderLackierungsAufpreisJob extends AbstractJob
{
    /**
     * @throws JsonException
     */
    public function __construct(Output $output, AkeneoPimClientInterface $pimClient)
    {
        parent::__construct($output, $pimClient);

        $variantsFile = __DIR__ . '/flip.json';
        if (is_file($variantsFile)) {
            $this->flipSkus = json_decode(file_get_contents($variantsFile), true, 512, JSON_THROW_ON_ERROR);
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
        $backup = [];
        $resultInfo = [];

        $affectedProducts = $this->getAffectedProductVariants();

        foreach ($affectedProducts as $product) {
            $price = json_decode($product['values']['graduated_price'][0]['data'], true, 512, JSON_THROW_ON_ERROR);

            $backup[$product['identifier']] = $product;

            $product = $this->setAttributeValueInProduct($product, 'graduated_price', json_encode($this->updatePrice($price), JSON_THROW_ON_ERROR), 'printplanet', 'de_DE');

            $products[] = $product;

            $resultInfo[$product['identifier']] = [
                'name' => $product['values']['name'][0]['data'],
                'material' => $product['values']['signs_material'][0]['data'],
                'size' => $product['values']['signs_size'][0]['data'],
                'supplierSku' => $product['values']['supplier_sku'][0]['data'],
            ];
        }

        file_put_contents(__DIR__ . 'backup.json', json_encode($backup, JSON_THROW_ON_ERROR));

        $this->runUpsert($products, $resultInfo, $force);
    }

    private function getAffectedProductVariants(): array
    {
        $searchBuilder = new SearchBuilder();
        $searchBuilder
            ->addFilter('family', 'IN', ['signs'])
            ->addFilter('graduated_price', 'CONTAINS', 'coating', ['locale' => 'de_DE', 'scope' => 'printplanet']);

        $searchFilters = $searchBuilder->getFilters();

        $cursor = $this->pimClient->getProductApi()->all(100, ['search' => $searchFilters, 'scope' => 'printplanet']);

        return iterator_to_array($cursor);
    }

    private function updatePrice(array $price): array
    {
        foreach ($price['adjustments'] as $index => $adjustment) {
            if ('coating' === $adjustment['type'] && 3.5 === $adjustment['amount']) {
                $price['adjustments'][$index]['amount'] = 5.0;
            }
        }

        return $price;
    }
}
