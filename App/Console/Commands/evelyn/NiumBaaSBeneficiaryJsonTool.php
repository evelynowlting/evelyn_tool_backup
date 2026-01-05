<?php

namespace App\Console\Commands\evelyn;

use App\Exceptions\HttpException\PayoutException;
use Illuminate\Console\Command;
use Swaggest\JsonDiff\JsonDiff;

class NiumBaaSBeneficiaryJsonTool extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'json:tool';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // $currentJson = \file_get_contents('nium_baas_current_schema.json');
        // $previousJson = \file_get_contents('nium_baas_prev_schema.json');

        $currentJson = \file_get_contents(__DIR__.'/cn_new_prod.json');
        $previousJson = \file_get_contents(__DIR__.'/cn_old_prod.json');

        dd(_jsonComparison($previousJson, $currentJson));

        throw_if(!(json_decode($currentJson) || json_decode($previousJson)), new PayoutException('[Nium BaaS Beneficiary] Invalid JSON format'));

        $jsonDiff = new JsonDiff(json_decode($previousJson), json_decode($currentJson), JsonDiff::REARRANGE_ARRAYS);
        $diff = $jsonDiff->getPatch()->jsonSerialize();
        $removed_data = [];
        if (null != $jsonDiff->getRemoved()) {
            $removed_data = json_decode(json_encode($jsonDiff->getRemoved()), true);
        }

        $result = [];
        $origin_value = '';
        for ($i = 0; $i < count($diff); ++$i) {
            $op = $diff[$i]->op;
            $path = $diff[$i]->path;
            $value = $diff[$i]->value ?? null;

            if ('add' == $op) {
                echo 'add';
                $result['add'][$path] = $value;
            }

            if ('remove' == $op) {
                $nodes = explode('/', \trim($path));
                array_shift($nodes);
                $node_path = implode('.', $nodes);

                $result['remove'][$path] = data_get($removed_data, $node_path);
            }

            // original path and value
            if ('test' == $op) {
                $origin_node = $path;
                $origin_value = $value;
            }

            if ('replace' == $op) {
                if ($origin_node == $path) {
                    $result['replace'][$path] = [
                        'previous' => $origin_value,
                        'latest' => $value,
                    ];
                    $origin_node = '';
                    $origin_value = '';
                }
            }
        }

        dd($result);

        return $result;
    }
}
