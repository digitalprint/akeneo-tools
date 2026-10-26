<?php

namespace App\Command;

use Akeneo\Pim\ApiClient\AkeneoPimClientBuilder;
use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use Akeneo\Pim\ApiClient\Exception\HttpException;
use Akeneo\Pim\ApiClient\Exception\UnauthorizedHttpException;
use Akeneo\Pim\ApiClient\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'pim:test:connection',
    description: 'Tests the connection and authentication to Akeneo PIM.',
    aliases: ['pim:connection:test', 'pim:test', 'pim:auth:test']
)]
class PimTestConnectionCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Tests the connection and authentication to Akeneo PIM.')
            ->addOption('staging', null, InputOption::VALUE_NONE, 'Use STAGING_* environment variables instead of PIM_*')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Override PIM API URL')
            ->addOption('client-id', null, InputOption::VALUE_REQUIRED, 'Override PIM Client ID')
            ->addOption('client-secret', null, InputOption::VALUE_REQUIRED, 'Override PIM Client Secret')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Override PIM Username')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Override PIM Password')
            ->setHelp(<<<EOT
The <info>%command.name%</info> command tests whether the configured Akeneo PIM API credentials and endpoint are working properly:

  <info>php %command.full_name%</info>

You can test staging credentials:
  <info>php %command.full_name% --staging</info>

Or test custom credentials directly:
  <info>php %command.full_name% --url=https://example.akeneo.com --client-id=xxx --client-secret=yyy --username=user --password=pass</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Akeneo PIM Connection & Authentication Test');

        $isStaging = (bool) $input->getOption('staging');
        $prefix = $isStaging ? 'STAGING' : 'PIM';

        $apiUrl = $input->getOption('url') ?: ($_ENV[$prefix . '_API_URL'] ?? $_SERVER[$prefix . '_API_URL'] ?? '');
        $clientId = $input->getOption('client-id') ?: ($_ENV[$prefix . '_CLIENT_ID'] ?? $_SERVER[$prefix . '_CLIENT_ID'] ?? '');
        $clientSecret = $input->getOption('client-secret') ?: ($_ENV[$prefix . '_CLIENT_SECRET'] ?? $_SERVER[$prefix . '_CLIENT_SECRET'] ?? '');
        $username = $input->getOption('username') ?: ($_ENV[$prefix . '_CLIENT_USER'] ?? $_SERVER[$prefix . '_CLIENT_USER'] ?? '');
        $password = $input->getOption('password') ?: ($_ENV[$prefix . '_CLIENT_PASS'] ?? $_SERVER[$prefix . '_CLIENT_PASS'] ?? '');

        // 1. Parameter / Environment validation
        $missing = [];
        if (empty($apiUrl)) {
            $missing[] = $prefix . '_API_URL';
        }
        if (empty($clientId)) {
            $missing[] = $prefix . '_CLIENT_ID';
        }
        if (empty($clientSecret)) {
            $missing[] = $prefix . '_CLIENT_SECRET';
        }
        if (empty($username)) {
            $missing[] = $prefix . '_CLIENT_USER';
        }
        if (empty($password)) {
            $missing[] = $prefix . '_CLIENT_PASS';
        }

        $io->section('Configuration');
        $configTable = new Table($output);
        $configTable->setHeaders(['Parameter', 'Source / Name', 'Value / Status']);
        $configTable->addRow(['Environment Prefix', $isStaging ? 'Staging' : 'Default / Production', $prefix]);
        $configTable->addRow(['API URL', $prefix . '_API_URL', $apiUrl ?: '<error>NOT SET</error>']);
        $configTable->addRow(['Client ID', $prefix . '_CLIENT_ID', $clientId ?: '<error>NOT SET</error>']);
        $configTable->addRow(['Username', $prefix . '_CLIENT_USER', $username ?: '<error>NOT SET</error>']);
        $configTable->addRow(['Client Secret', $prefix . '_CLIENT_SECRET', !empty($clientSecret) ? '<info>[CONFIGURED (' . strlen($clientSecret) . ' chars)]</info>' : '<error>NOT SET</error>']);
        $configTable->addRow(['Password', $prefix . '_CLIENT_PASS', !empty($password) ? '<info>[CONFIGURED (' . strlen($password) . ' chars)]</info>' : '<error>NOT SET</error>']);
        $configTable->render();

        if (!empty($missing)) {
            $io->error(sprintf(
                "Missing required environment variables:\n- %s\n\nPlease configure them in your .env.local file or provide them as command options.",
                implode("\n- ", $missing)
            ));

            return Command::FAILURE;
        }

        // 2. Authentication Step
        $io->section('1. Testing Authentication (OAuth2 Token Request)');
        $startTime = microtime(true);
        $pimClient = null;

        try {
            $builder = new AkeneoPimClientBuilder(rtrim($apiUrl, '/'));
            $pimClient = $builder->buildAuthenticatedByPassword(
                $clientId,
                $clientSecret,
                $username,
                $password
            );

            $token = $pimClient->getToken();
            $authDuration = round((microtime(true) - $startTime) * 1000, 2);

            if (empty($token)) {
                $io->error('Authentication failed: No access token received from API.');
                return Command::FAILURE;
            }

            $maskedToken = substr($token, 0, 8) . '...' . substr($token, -8);
            $io->success(sprintf('Authentication successful! (%s ms)', $authDuration));
            $io->writeln(sprintf(' <info>✓</info> Access Token: <comment>%s</comment>', $maskedToken));
            if ($pimClient->getRefreshToken()) {
                $maskedRefresh = substr($pimClient->getRefreshToken(), 0, 6) . '...' . substr($pimClient->getRefreshToken(), -6);
                $io->writeln(sprintf(' <info>✓</info> Refresh Token: <comment>%s</comment>', $maskedRefresh));
            }
        } catch (UnauthorizedHttpException $e) {
            $io->error([
                'Authentication Failed (401 Unauthorized)',
                'The PIM rejected the provided credentials. Please check:',
                ' - PIM_CLIENT_ID & PIM_CLIENT_SECRET (API Client configuration in Akeneo)',
                ' - PIM_CLIENT_USER & PIM_CLIENT_PASS (User credentials and role permissions)',
                'Details: ' . $e->getMessage()
            ]);
            return Command::FAILURE;
        } catch (UnprocessableEntityHttpException $e) {
            $io->error([
                'Authentication / Request Rejected (422 Unprocessable Entity)',
                'Details: ' . $e->getMessage(),
                'Please verify that the username and password match an existing and active Akeneo user.'
            ]);
            return Command::FAILURE;
        } catch (HttpException $e) {
            $io->error([
                sprintf('HTTP Error during authentication (Status Code: %s)', $e->getCode()),
                'Details: ' . $e->getMessage(),
                'Please verify the API URL: ' . $apiUrl
            ]);
            return Command::FAILURE;
        } catch (Throwable $e) {
            $io->error([
                'Connection/Network Error during authentication',
                'Class: ' . get_class($e),
                'Message: ' . $e->getMessage(),
                'Please check network connectivity, DNS resolution, and whether VPN is required.'
            ]);
            return Command::FAILURE;
        }

        // 3. API Communication / Permissions Smoke Test
        $io->section('2. Testing API Read Access (Endpoints Probe)');
        $probeStart = microtime(true);

        try {
            // Test 1: Fetch channels
            $channelsPage = $pimClient->getChannelApi()->listPerPage(10);
            $channels = $channelsPage->getItems();
            $channelCodes = array_map(static fn($c) => $c['code'] ?? 'unknown', $channels);

            // Test 2: Fetch locales
            $localesPage = $pimClient->getLocaleApi()->listPerPage(10);
            $locales = $localesPage->getItems();
            $localeCodes = array_map(static fn($l) => $l['code'] ?? 'unknown', $locales);

            $probeDuration = round((microtime(true) - $probeStart) * 1000, 2);

            $io->success(sprintf('API read test successful! (%s ms)', $probeDuration));

            $io->writeln(sprintf(' <info>✓</info> Available Channels (%d): <comment>%s</comment>', count($channelCodes), implode(', ', $channelCodes) ?: 'none'));
            $io->writeln(sprintf(' <info>✓</info> Active Locales (%d): <comment>%s</comment>', count($localeCodes), implode(', ', array_slice($localeCodes, 0, 5)) . (count($localeCodes) > 5 ? ' ...' : '')));

        } catch (Throwable $e) {
            $io->warning([
                'Authentication succeeded, but reading data from API failed.',
                'Message: ' . $e->getMessage(),
                'Please verify user permissions and API user role in Akeneo PIM.'
            ]);
            return Command::FAILURE;
        }

        $io->newLine();
        $io->success('All checks passed! Connection and authentication to Akeneo PIM are working properly.');

        return Command::SUCCESS;
    }
}
