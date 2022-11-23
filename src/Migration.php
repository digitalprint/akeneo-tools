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

    /**
     * @return int
     */
    public function readAttributeGroups() : int
    {
        $page = $this->currentClient->getAttributeGroupApi()->listPerPage($this->queryLimit, true);
        $this->payload = [];

        do {
            array_push($this->payload, ...$page->getItems());
        } while ($page = $page->getNextPage());

        return count($this->payload);
    }

    /**
     * @return void
     */
    public function writeAttributeGroups() : void
    {
        $upsertList = [];

        foreach ($this->payload as $item) {
            $upsertList[] = [
                "code" => $item['code'],
                "sort_order" => $item['sort_order'],
                "labels" => $item['labels'],
                "attributes" => []
            ];
        }

        $this->stagingClient->getAttributeGroupApi()->upsertList($upsertList);
    }

    /**
     * @return int
     */
    public function readAttributes() : int
    {
        $page = $this->currentClient->getAttributeApi()->listPerPage($this->queryLimit, true);
        $this->payload = [];

        do {
            array_push($this->payload, ...$page->getItems());
        } while ($page = $page->getNextPage());

        return count($this->payload);
    }

    /**
     * @return void
     */
    public function writeAttributes() : void
    {
        //$this->stagingClient->getAttributeApi()->create();
    }
}
