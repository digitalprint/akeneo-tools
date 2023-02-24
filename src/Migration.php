<?php

namespace App;

use \Akeneo\Pim\ApiClient\AkeneoPimClientBuilder;
use \Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use Akeneo\Pim\ApiClient\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Console\Output\OutputInterface;

class Migration
{
    /**
     * @var AkeneoPimClientInterface
     */
    private AkeneoPimClientInterface $currentClient;

    /**
     * @var AkeneoPimClientInterface
     */
    private AkeneoPimClientInterface $stagingClient;

    /**
     * @var int
     */
    protected int $queryLimit = 100;

    /**
     * @var array
     */
    private array $payload = [];

    /**
     * @var bool
     */
    private bool $noFileImport = false;

    public function __construct()
    {
        $clientBuilderStaging = new AkeneoPimClientBuilder($_ENV['STAGING_API_URL']);
        $this->stagingClient = $clientBuilderStaging->buildAuthenticatedByPassword(
            $_ENV['STAGING_CLIENT_ID'],
            $_ENV['STAGING_CLIENT_SECRET'],
            $_ENV['STAGING_CLIENT_USER'],
            $_ENV['STAGING_CLIENT_PASS'],
        );

        $clientBuilderCurrent = new AkeneoPimClientBuilder($_ENV['CURRENT_API_URL']);
        $this->currentClient = $clientBuilderCurrent->buildAuthenticatedByPassword(
            $_ENV['CURRENT_CLIENT_ID'],
            $_ENV['CURRENT_CLIENT_SECRET'],
            $_ENV['CURRENT_CLIENT_USER'],
            $_ENV['CURRENT_CLIENT_PASS'],
        );
    }

    private function setFamilyCodes($payload) {
        return $payload;
    }

    /**
     * @param array $payload
     * @return int
     */
    private function countPayload(array $payload) : int
    {
        $i = 0;
        foreach($payload as $page){
            $i += count($page);
        }
        return $i;
    }

    /**
     * @return void
     */
    private function dumpFirstAndDie() : void
    {
        dump($this->payload[0][0] ?? 'empty Array');
        die();
    }

    /**
     * @param array $page
     * @param array $newValues
     * @return array
     */
    private function overwriteValues(array $page, array $newValues = []) : array
    {
        $newPage = [];

        foreach ($page as $item) {
            $newPage[] =  array_merge($item, $newValues);
        }

        return $newPage;
    }

    /**
     * @return int
     */
    public function readAttributeGroups() : int
    {
        $page = $this->currentClient->getAttributeGroupApi()->listPerPage($this->queryLimit, true);
        $this->payload = [];

        do {
            $this->payload[] = $page->getItems();
        } while ($page = $page->getNextPage());

        return $this->countPayload($this->payload);
    }

    /**
     * @return void
     */
    public function writeAttributeGroups() : void
    {
        foreach ($this->payload as $page) {
            $newPage = $this->overwriteValues($page, [
                'attributes' => []
            ]);
            $this->stagingClient->getAttributeGroupApi()->upsertList($newPage);

            // Attribute options
            foreach ($page as $item) {
                foreach ($item['attributes'] as $attribute) {
                    $type = $this->currentClient->getAttributeApi()->get($attribute)['type'];

                    if (in_array($type, ['pim_catalog_simpleselect', 'pim_catalog_multiselect'])) {
                        $options = $this->currentClient->getAttributeOptionApi()->all($attribute, 100);
                        foreach ($options as $option) {
                            $this->stagingClient->getAttributeOptionApi()->upsert($attribute, $option['code'], $option);
                        }
                    }
                }
            }
        }
    }


    /**
     * @return int
     */
    public function readAttributes() : int
    {
        $page = $this->currentClient->getAttributeApi()->listPerPage($this->queryLimit, true);
        $this->payload = [];

        do {
            $this->payload[] = $page->getItems();
        } while ($page = $page->getNextPage());

        return $this->countPayload($this->payload);
    }

    /**
     * @return void
     */
    public function writeAttributes() : void
    {
        foreach ($this->payload as $page) {
            $newPage = $this->overwriteValues($page, [
                'validation_rule' => null
            ]);
            $this->stagingClient->getAttributeApi()->upsertList($newPage);
        }
    }

    /**
     * @return int
     */
    public function readChannels() : int
    {
        $page = $this->currentClient->getChannelApi()->listPerPage($this->queryLimit, true);
        $this->payload = [];

        do {
            $this->payload[] = $page->getItems();
        } while ($page = $page->getNextPage());

        return $this->countPayload($this->payload);
    }

    /**
     * @return void
     */
    public function writeChannels() : void
    {
        foreach ($this->payload as $page) {
            $this->stagingClient->getChannelApi()->upsertList($page);
        }
    }

    /**
     * @return int
     */
    public function readCategories() : int
    {
        $page = $this->currentClient->getCategoryApi()->listPerPage($this->queryLimit, true);
        $this->payload = [];

        do {
            $this->payload[] = $page->getItems();
        } while ($page = $page->getNextPage());

        return $this->countPayload($this->payload);
    }

    /**
     * @return void
     */
    public function writeCategories() : void
    {
        foreach ($this->payload as $page) {
            $this->stagingClient->getCategoryApi()->upsertList($page);
        }
    }

    /**
     * @return int
     */
    public function readAssociationTypes() : int
    {
        $page = $this->currentClient->getAssociationTypeApi()->listPerPage($this->queryLimit, true);
        $this->payload = [];

        do {
            $this->payload[] = $page->getItems();
        } while ($page = $page->getNextPage());

        return $this->countPayload($this->payload);
    }

    /**
     * @return void
     */
    public function writeAssociationTypes() : void
    {
        foreach ($this->payload as $page) {
            $this->stagingClient->getAssociationTypeApi()->upsertList($page);
        }
    }

    /**
     * @return int
     */
    public function readFamilies() : int
    {
        $page = $this->currentClient->getFamilyApi()->listPerPage($this->queryLimit, true);
        $this->payload = [];

        do {
            $this->payload[] = $page->getItems();
        } while ($page = $page->getNextPage());

        return $this->countPayload($this->payload);
    }

    /**
     * @return void
     */
    public function writeFamilies() : void
    {
        foreach ($this->payload as $page) {
            $this->stagingClient->getFamilyApi()->upsertList($page);
        }
    }

    /**
     * @return int
     */
    public function readFamilyVariants() : int
    {
        $familyCodes = [];

        $this->readFamilies();
        foreach ($this->payload as $page) {
            foreach ($page as $item) {
                $familyCodes[] = $item['code'];
            }
        }

        $this->payload = [];

        foreach ($familyCodes as $familyCode) {
            $page = $this->currentClient->getFamilyVariantApi()->listPerPage($familyCode, $this->queryLimit);
            $items = $page->getItems();

            if (count($items) > 0) {
                $this->payload[$familyCode] = $items;
            }
        }

        return $this->countPayload($this->payload);
    }

    /**
     * @return void
     */
    public function writeFamilyVariants() : void
    {
        foreach ($this->payload as $code => $page) {
            $this->stagingClient->getFamilyVariantApi()->upsertList($code, $page);
        }
    }

    /**
     * @return int
     */
    public function readProductModels() : int
    {
        $page = $this->currentClient->getProductModelApi()->listPerPage($this->queryLimit, true);
        $this->payload = [];

        do {
            $this->payload[] = $page->getItems();
        } while ($page = $page->getNextPage());

        return $this->countPayload($this->payload);
    }

    /**
     * @return void
     */
    public function writeProductModels() : void
    {
        foreach ($this->payload as $page) {
            if ($this->noFileImport) {
                foreach ($page as $key => $item) {
                    unset(
                        $page[$key]['values']['printfile'],
                    );
                }
            }

            $this->stagingClient->getProductModelApi()->upsertList($page);
            echo ".";
        }
    }

    /**
     * @return int
     */
    public function readProducts() : int
    {
        $page = $this->currentClient->getProductApi()->listPerPage($this->queryLimit, true);

        $this->payload = [];

        do {
            $this->payload[] = $page->getItems();
        } while ($page = $page->getNextPage());

        return $this->countPayload($this->payload);
    }

    /**
     * @return void
     */
    public function writeProducts() : void
    {
        foreach ($this->payload as $page) {
            if ($this->noFileImport) {
                foreach ($page as $key => $item) {
                    unset(
                        $page[$key]['values']['image_01'],
                        $page[$key]['values']['image_02'],
                        $page[$key]['values']['image_03'],
                        $page[$key]['values']['image_04'],
                        $page[$key]['values']['image_05'],
                        $page[$key]['values']['image_06'],
                        $page[$key]['values']['image_07'],
                        $page[$key]['values']['image_08'],
                        $page[$key]['values']['image_main'],
                        $page[$key]['values']['product_image'],
                        $page[$key]['values']['printfile'],
                    );
                }
            }

            $this->stagingClient->getProductApi()->upsertList($page);
            echo ".";
        }
    }


    /**
     * @return int
     */
    public function readTest() : int
    {

        $product = $this->currentClient->getProductApi()->get("74876fa2-7f9f-41bb-9a8a-117580c8a860");

//        $product['values']['image_01'][0]['data'] = "c/3/c/5/c3c5a31237ed39f2bbe1526a378ec805a94c4798_sergey_shmidt_koy6FlCCy5s_unsplash.jpeg";

        //dump($product['values']['image_01']);


        try {
            $test = $this->stagingClient->getProductApi()->upsert("74876fa2-7f9f-41bb-9a8a-117580c8a860", $product);
        } catch (UnprocessableEntityHttpException $e) {
            dd($e->getMessage());
        }


        dd($test);
        return 1;
    }


}
