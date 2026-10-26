<?php

namespace App\Command;

use Akeneo\Pim\ApiClient\AkeneoPimClientBuilder;
use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractPimCommand extends Command
{
    protected AkeneoPimClientInterface $pimClient;

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        set_time_limit(60 * 60);
        ini_set('memory_limit', '1024M');
        ini_set('precision', -1);
        ini_set('serialize_precision', -1);

        $apiUrl = $_ENV['PIM_API_URL'] ?? $_SERVER['PIM_API_URL'] ?? '';
        $clientId = $_ENV['PIM_CLIENT_ID'] ?? $_SERVER['PIM_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['PIM_CLIENT_SECRET'] ?? $_SERVER['PIM_CLIENT_SECRET'] ?? '';
        $username = $_ENV['PIM_CLIENT_USER'] ?? $_SERVER['PIM_CLIENT_USER'] ?? '';
        $password = $_ENV['PIM_CLIENT_PASS'] ?? $_SERVER['PIM_CLIENT_PASS'] ?? '';

        $pimClient = new AkeneoPimClientBuilder(rtrim($apiUrl, '/'));
        $this->pimClient = $pimClient->buildAuthenticatedByPassword(
            $clientId,
            $clientSecret,
            $username,
            $password,
        );
    }
}
