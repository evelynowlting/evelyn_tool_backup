<?php

namespace App\Console\Commands;

use App\Enums\AmlApplicantEnum;
use App\Enums\AmlRedirectTypeEnum;
use App\Enums\PayoutChannel\CrossBorderPayoutEnum;
use App\Models\PayoutGatewayColumns;
use App\Models\Vendor;
use App\Services\AMLService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SyncAmlDataKey extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:aml_data_key {--payout_gateway=} {--country=} {--type=} {--all}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync AML Data Key';

    private $amlService;

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
    public function handle(AMLService $amlService)
    {
        $this->amlService = $amlService;
        $all = $this->option('all');

        $payout_network_config = config('payout_network');

        if (!$all) {
            $payout_gateway = $this->option('payout_gateway');
            $type = $this->option('type');
            $country = $this->option('country');

            if (is_null($payout_gateway)) {
                $payout_gateway = $this->choice('What\'s payout gateway?', [
                    AmlRedirectTypeEnum::CATHAY,
                    AmlRedirectTypeEnum::VISA,
                    AmlRedirectTypeEnum::FIRSTBANK,
                ]);
            }

            $channel_drivers = array_column($payout_network_config, 'channel_driver');

            $channel_driver_key = array_flip($channel_drivers)[$payout_gateway] ?? null;

            if (null === $channel_driver_key) {
                $this->error("$payout_gateway not found in config.");

                return -1;
            }

            $allow_pairs = $payout_network_config[$channel_driver_key]['allow_countries'];

            $owlpay_payout_gateway_key = $payout_network_config[$channel_driver_key]['key'];

            $allow_countries = array_values(array_unique(array_column($allow_pairs, 'country_iso')));

            if (is_null($country)) {
                $country = $this->choice('What\'s country?', $allow_countries);
            }

            if (is_null($type)) {
                $type = $this->choice('What\'s type?', [
                    AmlApplicantEnum::INDIVIDUAL,
                    AmlApplicantEnum::COMPANY,
                ]);
            }
            [$data_keys_list, $required_list, $defaultPageKeys] = $this->getSchemaFromOwlTingAML($payout_gateway, $country, $type);
            $this->syncPayoutGatewayColumns($data_keys_list, $country, $type, $owlpay_payout_gateway_key, $required_list, $defaultPageKeys);
        } else {
            foreach ($payout_network_config as $payout_network) {
                $channel_driver = $payout_network['channel_driver'];
                $owlpay_payout_gateway_key = $payout_network['key'];

                $allow_countries = array_values(array_unique(array_column($payout_network['allow_countries'], 'country_iso')));

                foreach ($allow_countries as $allow_country) {
                    $applicant_types = AmlApplicantEnum::toArray();

                    // VCC only allow company
                    if (CrossBorderPayoutEnum::VISA_VPA == $owlpay_payout_gateway_key) {
                        $applicant_types = [AmlApplicantEnum::COMPANY];
                    }

                    $country = $allow_country;

                    foreach ($applicant_types as $applicant_type) {
                        [$data_keys_list, $required_list, $defaultPageKeys] = $this->getSchemaFromOwlTingAML($channel_driver, $allow_country, $applicant_type);
                        $this->syncPayoutGatewayColumns($data_keys_list, $country, $applicant_type, $owlpay_payout_gateway_key, $required_list, $defaultPageKeys);
                    }
                }
            }
        }

        return 0;
    }

    private function syncPayoutGatewayColumns($data_keys_list, $country, $type, $owlpay_payout_gateway_key, $required_list, $defaultPageKeys)
    {
        foreach ($data_keys_list as $source => $data_keys) {
            $aml_source = null;
            $pageKey = null;

            if (false !== strpos($source, 'owlting_aml-')) {
                $aml_source = explode('-', $source)[0];
                $pageKey = explode('-', $source)[1];
            }

            $this->info('--------------');
            $this->info("[Sync AML Data Key] Payout Gateway: $owlpay_payout_gateway_key");
            $this->info('[Sync AML Data Key] Source:'.($aml_source ?? 'owlpay'));
            $this->info('[Sync AML Data Key] Page Key:'.$pageKey ?? '');
            $this->info("[Sync AML Data Key] Country: $country");
            $this->info("[Sync AML Data Key] Type: $type");

            $data_keys_insert = array_map(function ($data_key) use ($source, $country, $type, $owlpay_payout_gateway_key, $aml_source, $pageKey, $required_list, $defaultPageKeys) {
                return [
                    'data_key' => $data_key['key'],
                    'i18n_key' => $data_key['label'],
                    'source' => $aml_source ?? $source,
                    'country' => $country,
                    'applicant' => $type,
                    'payout_gateway' => $owlpay_payout_gateway_key,
                    /*
                        owlpay only support debit now.
                        application => credit
                        vendor => debit
                    */
                    'model_type' => (new Vendor())->getMorphClass(),
                    'aml_page_key' => $pageKey ?? null,
                    'is_required' => (in_array($data_key['key'], $required_list) || 'owlpay' == $source),
                    'is_default' => match ($source) {
                        'owlpay' => true,
                        default => in_array($pageKey, $defaultPageKeys)
                    },
                    'sub_form_key' => $data_key['subFormKey'] ?? null,
                ];
            }, $data_keys);
            $exist_items = PayoutGatewayColumns::where([
                'country' => $country,
                'applicant' => $type,
                'payout_gateway' => $owlpay_payout_gateway_key,
                'model_type' => (new Vendor())->getMorphClass(),
                'source' => $aml_source ?? $source,
                'aml_page_key' => $pageKey ?? null,
            ])->get();

            $mapping_keys = [
                'data_key',
                'source',
                'country',
                'applicant',
                'payout_gateway',
                'model_type',
                'aml_page_key',
                'is_required',
                'i18n_key',
                'is_default',
                'sub_form_key',
            ];

            $exist_items_array = $exist_items->map(function ($item) use ($mapping_keys) {
                return $item->only($mapping_keys);
            })->toArray();

            $insert_mapping = array_map(function ($data_keys) use ($mapping_keys) {
                return \Arr::only($data_keys, $mapping_keys);
            }, $data_keys_insert);

            $insert_list = $this->getInsertList($insert_mapping, $exist_items_array);

            $remove_list = $this->getRemoveList($insert_mapping, $exist_items_array);

            foreach ($remove_list as $remove) {
                $this->info('[Sync AML Data Key] remove count:'.count($remove));
                \DB::table('payout_gateway_columns')->where($remove)->delete();
            }

            $this->info('[Sync AML Data Key] insert count:'.count($insert_list));
            \DB::table('payout_gateway_columns')->insert($insert_list);
        }
    }

    private function getSchemaFromOwlTingAML($payout_gateway, $country, $type)
    {
        $base_url = config('AML.url');
        $secret = $this->amlService->getAMLSecretByCountry($country);

        $get_page_list_response = Http::withHeaders(['x-owlting-secret' => $secret])
            ->get("$base_url/v1/internal/schema/$payout_gateway/debit/$country/$type?pageList=true&translation=false");

        $data = $get_page_list_response->json()['data'];

        $defaultPageKeys = $data['default'] ?? [];

        $pageKeys = array_unique(data_get($data, '*.*', []));

        $data_keys_list = [
            'owlpay' => [
                [
                    'key' => 'application_vendor_uuid',
                    'label' => 'export.vendors.application_vendor_uuid',
                ],
                [
                    'key' => 'name',
                    'label' => 'export.vendors.name',
                ],
                [
                    'key' => 'country',
                    'label' => 'export.vendors.vendor_information_address_country',
                ],
                [
                    'key' => 'email',
                    'label' => 'export.vendors.email',
                ],
            ],
        ];

        $required_list = [];
        foreach ($pageKeys as $pageKey) {
            $page_key_response = Http::withHeaders(['x-owlting-secret' => $secret])
                ->get("$base_url/v1/internal/schema/$payout_gateway/debit/$country/$type/$pageKey?translation=false");

            $response_data = $page_key_response->json()['data'] ?? [];

            $this->parseDataKey($response_data, $data_keys_list, $pageKey);

            $this->parseRequired($response_data, $required_list);

            $required_list = array_unique($required_list);
        }

        return [$data_keys_list, $required_list, $defaultPageKeys];
    }

    private function getInsertList($insert_mapping, $exist_items_array)
    {
        return array_udiff($insert_mapping, $exist_items_array, function ($a, $b) {
            return strcmp($a['data_key'], $b['data_key']);
        });
    }

    private function getRemoveList($insert_mapping, $exist_items_array)
    {
        return array_udiff($exist_items_array, $insert_mapping, function ($a, $b) {
            return strcmp($a['data_key'], $b['data_key']);
        });
    }

    private function parseDataKey($items, &$data_keys_list, $pageKey, $label = null, $subFormKey = null)
    {
        if (isset($items['subFormKey'])) {
            $subFormKey = $items['subFormKey'];
        }

        if (is_array($items)) {
            foreach ($items as $key => $item) {
                if (is_array($item)) {
                    $label = $item['label'] ?? $label;

                    $this->parseDataKey($item, $data_keys_list, $pageKey, $label, $subFormKey);
                }

                if ('dataKey' != $key) {
                    continue;
                }

                if (is_array($label)) {
                    $label = Arr::first($label);
                }

                // AML schema city, area, address 的 i18 key 均吐回 address.label; phoneCode, phoneNumber 的 i18n key 均吐回 phone.label，導致 Excel phoneCode 欄位顯示"電話"，當輸入值超過 AML phoneCode 欄位長度時，匯入 Vendor 時，在 AML 專案會出現 Error，所以補上依 response 的 dataKey 值判斷
                $label = match ($item) {
                    'city' => 'city.placeholder',
                    'area' => 'area.placeholder',
                    'phoneCode' => 'phoneCode.label',
                    default => Str::after($label, 'i18n:')
                };

                $data_keys_list["owlting_aml-$pageKey"][] = [
                    'key' => $item,
                    'label' => $label,
                    'subFormKey' => $subFormKey,
                ];
            }
        }
    }

    private function parseRequired($items, &$required_list)
    {
        foreach ($items as $item) {
            $this->parseChildrenRequired($item['children'], $required_list);
        }
    }

    private function parseChildrenRequired($children, &$required_list)
    {
        foreach ($children as $child) {
            if (isset($child['validator']['rule']) && is_array($child['validator']['rule']) && isset($child['validator']['name'])) {
                foreach ($child['validator']['rule'] as $rule) {
                    if (isset($rule['required']) && $rule['required']) {
                        $required_list[] = $child['validator']['name'];
                    }
                }
            } elseif (isset($child['children'])) {
                $this->parseChildrenRequired($child['children'], $required_list);
            }
        }
    }
}
