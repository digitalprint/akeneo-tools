<?php

namespace App;

use \Akeneo\Pim\ApiClient\AkeneoPimClientBuilder;
use \Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
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
            $this->stagingClient->getProductModelApi()->upsertList($page);
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
            echo ".";
        } while ($page = $page->getNextPage());

        return $this->countPayload($this->payload);
    }

    /**
     * @return void
     */
    public function writeProducts() : void
    {
        foreach ($this->payload as $page) {
            foreach ($page as $key => $item) {
                $tmp = $this->stagingClient->getProductApi()->get($item['identifier']);
            }


            $this->stagingClient->getProductApi()->upsertList($page);
            echo ".";
        }
    }


}
