<?php
require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;
use App\MigrateCommand;
use Symfony\Component\Dotenv\Dotenv;

$application = new Application();
$application->add(new MigrateCommand());

$dotenv = new Dotenv();
$dotenv->load(__DIR__.'/.env', __DIR__.'/.env.local');

$application->run();
