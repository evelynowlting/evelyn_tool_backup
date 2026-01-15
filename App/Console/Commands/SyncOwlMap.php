<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class SyncOwlMap extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:owlmap {--country_id=} {--sync_all}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync OwlMap';

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
        $response = Http::retry(3)
            ->withHeaders([
                'x-owlting-secret' => config('services.owlmap.key'),
            ])
            ->timeout(10)
            ->get(config('services.owlmap.administrative').'/country');

        $countries = $response->json()['data'] ?? [];

        Storage::put('owlmap/countries.json', json_encode($countries, JSON_UNESCAPED_UNICODE));

        $countries_id_array = array_column($countries, 'id');

        $country_id = $this->option('country_id');

        $is_sync_all_sub_place = $this->option('sync_all') ?? false;

        if (null != $country_id && in_array($country_id, $countries_id_array)) {
            $response = Http::retry(3)
                ->withHeaders([
                    'x-owlting-secret' => config('services.owlmap.key'),
                ])
                ->timeout(10)
                ->get(config('services.owlmap.administrative').'/'.$country_id);

            $sub_place = $response->json()['data'] ?? [];

            $path = 'owlmap/sub_place/'.$country_id.'.json';
            Storage::put($path, json_encode($sub_place, JSON_UNESCAPED_UNICODE));

            $message = "Sync country_id: $country_id sub_place, sync success";
            $this->info($message);

            _owlPayLog('command_sync_owlmap', compact('message'), 'system');
        }

        if ($is_sync_all_sub_place) {
            $total_count = count($countries_id_array);

            $times = 1;
            foreach ($countries_id_array as $country_id) {
                $progress = "[$times/$total_count] ";
                $response = Http::retry(3)
                    ->withHeaders([
                        'x-owlting-secret' => config('services.owlmap.key'),
                    ])
                    ->timeout(10)
                    ->get(config('services.owlmap.administrative').'/'.$country_id);

                $sub_place = $response->json()['data'] ?? [];

                $path = 'owlmap/sub_place/'.$country_id.'.json';
                Storage::put($path, json_encode($sub_place, JSON_UNESCAPED_UNICODE));

                $message = "Sync country_id: $country_id sub_place, sync success";
                $this->info($progress.$message);

                _owlPayLog('command_sync_owlmap', compact('message'), 'system');

                ++$times;
            }
        }
    }
}
