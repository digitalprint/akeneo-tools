<?php

namespace App;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCommand extends Command {
    /**
     * @see Command
     */
    protected function configure(): void
    {
        $this
            ->setName('migrate:run')
            ->setDescription('Führt die Migration durch.')
            ->setHelp(
                <<<EOT
Der Befehl <info>%command.name%</info> führt die Migration durch.
EOT
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null
     */
    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        set_time_limit(60 * 60);
        ini_set('memory_limit', '1024M');

        $migration = new Migration();
        $migrationList = [
            "channels" => "Channels",
            "families" => "Families",
            "attributes" => "Attributes",
            "attributeGroups" => "Attribute groups",
            "categories" => "Categories",
            "associationTypes" => "Association types",
            "familyVariants" => "Families variants",
            "productModels" => "Product models",
            "products" => "Products",
        ];

        $standardOutput = $output->section();
        $progressBarOutput = $output->section();
        $progressBar = new ProgressBar($progressBarOutput, count($migrationList));

        foreach ($migrationList as $function => $name) {
            $standardOutput->writeln("- read $name");
            $count = $migration->{"read" . lcfirst($function)}();
            $standardOutput->writeln("- write $name ($count)");
            $migration->{"write" . lcfirst($function)}();
            $progressBar->advance();
        }

        $progressBar->finish();
        $standardOutput->writeln("Done!");

        return Command::SUCCESS;
    }
}
