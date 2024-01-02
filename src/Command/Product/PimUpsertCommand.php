<?php

/** @noinspection UnusedConstructorDependenciesInspection */

namespace App\Command\Product;

use Akeneo\Pim\ApiClient\AkeneoPimClientBuilder;
use Akeneo\Pim\ApiClient\AkeneoPimClientInterface;
use App\Command\Product\Jobs\FotoAufAluDibond\FotoAufAluDibondJob;
use App\Command\Product\Jobs\FotoAufHartschaumplatte\FotoAufHartschaumplatteJob;
use App\Command\Product\Jobs\FotoAufHolz\FotoAufHolzJob;
use App\Command\Product\Jobs\FotoHinterAcrylglas\FotoHinterAcrylglasJob;
use App\Command\Product\Jobs\FotoPoster\FotoPosterJob;
use App\Command\Product\Jobs\JobInterface;
use App\Command\Product\Jobs\WandbildKonturschnitt\WandbildKonturschnitt;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

#[AsCommand(name: 'product:pim:upsert')]
class PimUpsertCommand extends Command
{
    private array $availableJobs = [
        FotoHinterAcrylglasJob::class,
        FotoAufHartschaumplatteJob::class,
        FotoAufAluDibondJob::class,
        FotoPosterJob::class,
        FotoAufHolzJob::class,
        WandbildKonturschnitt::class,
    ];

    protected AkeneoPimClientInterface $pimClient;

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        set_time_limit(60 * 60);
        ini_set('memory_limit', '1024M');
        ini_set('precision', -1);
        ini_set('serialize_precision', -1);

        $pimClient = new AkeneoPimClientBuilder($_ENV['PIM_API_URL']);
        $this->pimClient = $pimClient->buildAuthenticatedByPassword(
            $_ENV['PIM_CLIENT_ID'],
            $_ENV['PIM_CLIENT_SECRET'],
            $_ENV['PIM_CLIENT_USER'],
            $_ENV['PIM_CLIENT_PASS'],
        );
    }

    /**
     * @see Command
     */
    protected function configure(): void
    {
        $this
            ->setName('product:pim:upsert')
            ->setDescription('Executes a job from a list that performs a mass update in the PIM, e.g. to change the prices for many variants.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'The job is really done. Without this option there is only a preview.')
            ->setHelp(<<<EOT
This command <info>%command.name%</info> executes a job from a list that performs a mass update in the PIM, e.g. to change the prices for many variants.

E.g.:
<info>php %command.full_name%</info>
EOT
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int|null
     */
    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $helper = $this->getHelper('question');
        $question = new ChoiceQuestion(
            'Please select the job you want to run.',
            $this->availableJobs
        );
        $jobName = $helper->ask($input, $output, $question);
        $output->writeln('You have just selected: ' . $jobName);

        /** @var JobInterface $job */
        $job = new $jobName($output, $this->pimClient);

        $output->writeln('<info>starting job...</info>');

        $job->execute($input->getOption('force'));

        $output->writeln('<info>... job end.</info>');

        return Command::SUCCESS;
    }
}
