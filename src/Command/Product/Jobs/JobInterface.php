<?php

namespace App\Command\Product\Jobs;

interface JobInterface
{
    public function execute(bool $force = false): void;
}
