<?php /** @noinspection DuplicatedCode */

namespace App\Command;

use Akeneo\Pim\ApiClient\AkeneoPimClientBuilder;
use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use Akeneo\Pim\ApiClient\Search\SearchBuilder;
use Illuminate\Support\Arr;
use JetBrains\PhpStorm\NoReturn;
use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PimUpdatePriceCommand extends Command {

    private AkeneoPimClientInterface $pimClient;

    public function __construct(string $name = null)
    {
        set_time_limit(60 * 60);
        ini_set('memory_limit', '1024M');
        ini_set('precision', -1);
        ini_set('serialize_precision', -1);

        $pimClient = new AkeneoPimClientBuilder($_ENV['CURRENT_API_URL']);
        $this->pimClient = $pimClient->buildAuthenticatedByPassword(
            $_ENV['CURRENT_CLIENT_ID'],
            $_ENV['CURRENT_CLIENT_SECRET'],
            $_ENV['CURRENT_CLIENT_USER'],
            $_ENV['CURRENT_CLIENT_PASS'],
        );

        parent::__construct($name);
    }

    /**
     * @see Command
     */
    protected function configure(): void
    {
        $this
            ->setName('pim:update:price')
            ->setDescription('Bulk Update von Preisen bei ganz vielen Varianten.')
            ->addArgument('products', InputArgument::REQUIRED, 'The products that should be updated. UUIDs whitespace seperated or `all` for all products')
            ->setHelp(
                <<<EOT
Der Befehl <info>%command.name%</info> Bulk Update von Preisen bei ganz vielen Varianten.
EOT
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $products = $input->getArgument('products');

        // $this->updateDrillHoles($input, $output);

        $this->updateCoating($input, $output);

        // $this->getProductByCode('a614043f-382e-4b6f-b408-c45ff281518c');

        return Command::SUCCESS;
    }

    private function getProductByCode(string $code): void
    {
        $product = $this->pimClient->getProductApi()->get($code);

        dd($product);
    }

    /**
     * @throws JsonException
     */
    private function updateDrillHoles(InputInterface $input, OutputInterface $output): void
    {
        $materials = [
            'plastic_5mm',
            'plastic_10mm',
            'acryl_5mm',
            'acryl_8mm',
            'alu_dibond_silver',
            'alu_dibond_white'
        ];

        $sizesWithoutDrillHoles = [
            '15x5',
            '21x7',
            '30x10',
            '42x14',
            '60x20',
            '84x28',
            '15x3_8',
            '21x5_3',
            '30x7_5',
            '42x10_5',
            '60x15',
            '84x21',
        ];

        $searchBuilder = new SearchBuilder();
        $searchBuilder
            ->addFilter('family', 'IN', ['signs'])
            ->addFilter('signs_material', 'IN', $materials)
            ->addFilter('signs_size', 'NOT IN', $sizesWithoutDrillHoles)
//            ->addFilter('supplier_sku', '=', '241263')
            ;

        $searchFilters = $searchBuilder->getFilters();

        $products = $this->pimClient->getProductApi()->all(50, [
            'search' => $searchFilters,
            'scope' => 'printplanet']
        );

        $result = [];

        foreach ($products as $product) {

            $productUpdated = false;

            $supplierParameter = $product['values']['supplier_parameter'] ?? null;
            if (null !== $supplierParameter && str_starts_with($supplierParameter[0]['data'], '{"is_coated')) {
                $json = json_decode($supplierParameter[0]['data'], true, 512, JSON_THROW_ON_ERROR);

                if (! isset($json['has_drill_holes'])) {
                    $json['has_drill_holes'] = "{{ getSupplierParam('hasDrillHoles', productConfiguration) }}";

                    $product['values']['supplier_parameter'][0]['data'] = json_encode($json, JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);

                    $productUpdated = true;
                }
            } else {
                $product['values']['supplier_parameter'] = [
                    [
                        'locale' => null,
                        'scope' => null,
                        'data' => '{"is_coated":"{{ getSupplierParam(\'isCoated\', productConfiguration) }}","has_drill_holes":"{{ getSupplierParam(\'hasDrillHoles\', productConfiguration) }}"}',
                    ]
                ];
                $productUpdated = true;
            }


            $graduatedPrice = $product['values']['graduated_price'] ?? null;
            if (null !== $graduatedPrice && str_starts_with($graduatedPrice[0]['data'], '{"type"')) {
                $json = json_decode($graduatedPrice[0]['data'], true, 512, JSON_THROW_ON_ERROR);

                if (! isset($json['adjustments'])) {
                    $json['adjustments'] = [
                        [
                            'amount' => 3.0,
                            'type' => 'drill_hole',
                        ]
                    ];

                    $product['values']['graduated_price'][0]['data'] = json_encode($json, JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);

                    $productUpdated = true;
                }
            }

            if (true === $productUpdated) {
                $result[] = $product;
            }
        }

        $chunks = array_chunk($result, 50);

        if (! empty($result)) {

            foreach ($chunks as $chunk) {
                $output->writeln("Updating #" . count($chunk) . " products...");

                $this->pimClient->getProductApi()->upsertList($chunk);
            }

        }
    }

    /**
     * @throws JsonException
     */
    private function updateCoating(InputInterface $input, OutputInterface $output): void
    {
        $materials = [
//            'plastic_5mm',
//            'plastic_10mm',
//            'alu_dibond_silver',
//            'alu_dibond_white',
            'tin_sign'
        ];

        $searchBuilder = new SearchBuilder();
        $searchBuilder
            ->addFilter('family', 'IN', ['signs'])
            ->addFilter('signs_material', 'IN', $materials)
            // ->addFilter('supplier_sku', '=', '218523')
            ;

        $searchFilters = $searchBuilder->getFilters();

        $products = $this->pimClient->getProductApi()->all(50, [
            'search' => $searchFilters,
            'scope' => 'printplanet']
        );

        $result = [];

        foreach ($products as $product) {

            $productUpdated = false;

            $supplierParameter = $product['values']['supplier_parameter'] ?? null;
            if (null !== $supplierParameter) {
                $json = json_decode($supplierParameter[0]['data'], true, 512, JSON_THROW_ON_ERROR);

                if (! isset($json['is_coated'])) {
                    $json['is_coated'] = "{{ getSupplierParam('isCoated', productConfiguration) }}";

                    $product['values']['supplier_parameter'][0]['data'] = json_encode($json, JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);

                    $productUpdated = true;
                }
            } else {
                $product['values']['supplier_parameter'] = [
                    [
                        'locale' => null,
                        'scope' => null,
                        'data' => '{"is_coated":"{{ getSupplierParam(\'isCoated\', productConfiguration) }}"}',
                    ]
                ];
                $productUpdated = true;
            }


            $graduatedPrice = $product['values']['graduated_price'] ?? null;
            if (null !== $graduatedPrice && str_starts_with($graduatedPrice[0]['data'], '{"type"')) {
                $json = json_decode($graduatedPrice[0]['data'], true, 512, JSON_THROW_ON_ERROR);


                if (! isset($json['adjustments'])) {
                    $json['adjustments'] = [
                        [
                            'amount' => 3.5,
                            'type' => 'coating',
                        ]
                    ];

                    $product['values']['graduated_price'][0]['data'] = json_encode($json, JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);

                    $productUpdated = true;
                } else if (! in_array('coating', Arr::pluck($json['adjustments'], 'type'), true)) {
                    $json['adjustments'][] = [
                        'amount' => 3.5,
                        'type' => 'coating',
                    ];

                    $product['values']['graduated_price'][0]['data'] = json_encode($json, JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);

                    $productUpdated = true;
                }
            }

            if (true === $productUpdated) {
                $result[] = $product;
            }
        }

        $chunks = array_chunk($result, 50);

        if (! empty($result)) {

            foreach ($chunks as $chunk) {
                $output->writeln("Updating #" . count($chunk) . " products...");

                $this->pimClient->getProductApi()->upsertList($chunk);
            }

        }
    }
}
