<?php

namespace App\Command\Product\Jobs\FotoHinterAcrylglas;

use App\Command\Product\Jobs\AbstractJob;

class FotoHinterAcrylglasJob extends AbstractJob
{
    protected array $attributesToChange = [
        'sku',
//        'supplier_sku',
//        'is_in_stock',
//        'stock_quantity',
//        'active',
//        'base_price',
//        'graduated_price',
        'printarea_width',
//        'printarea_height',
//        'printarea_section_variable',
//        'dpi',
//        'cpp_start_design_id',
    ];

    public function runSku(): void
    {

    }

    public function runPrintareaWidth(): void
    {

    }

}
