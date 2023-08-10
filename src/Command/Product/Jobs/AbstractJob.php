<?php

namespace App\Command\Product\Jobs;

use Illuminate\Support\Str;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Output\Output;

class AbstractJob implements JobInterface
{
    /**
     * @var Output
     */
    protected Output $output;

    protected array $attributesToChange = [];

    public function __construct(Output $output)
    {
        $this->output = $output;
    }

    public function run(): void
    {
        foreach ($this->attributesToChange as $attribute) {
            $methodName = $this->guessAttributesChangeMethodName($attribute);

            if (false === method_exists($this, $methodName)) {
                throw new RuntimeException("The method [$methodName] could not be found in your class. Please provide this method or delete the attribute [$attribute] in the attribute list.");
            }
        }
    }

    protected function guessAttributesChangeMethodName(string $attributeName): string
    {
        return 'run' . ucfirst(Str::camel($attributeName));
    }
}
