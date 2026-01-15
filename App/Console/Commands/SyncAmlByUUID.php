<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\BaseInformation;
use App\Models\Vendor;
use App\Services\InternalService;
use Illuminate\Console\Command;

class SyncAmlByUUID extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:aml_by_uuid {--uuids=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Aml remit info by uuid';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private InternalService $internalService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $uuids = $this->option('uuids');

        $uuids = explode(',', $uuids);

        if (!empty(array_filter($uuids))) {
            $notify_data = BaseInformation::leftJoin('applications', function ($join) {
                $join->on('base_information.model_id', '=', 'applications.id')
                    ->where('base_information.model_type', 'application');
            })->leftJoin('vendors', function ($join) {
                $join->on('base_information.model_id', '=', 'vendors.id')
                        ->where('base_information.model_type', 'vendor');
            })
                ->where(function ($query) use ($uuids) {
                    $query->whereIn('applications.uuid', $uuids)
                        ->orWhereIn('vendors.uuid', $uuids);
                })
                ->groupBy('aml_uuid')
                ->get('aml_uuid')
                ->pluck('aml_uuid')
                ->filter()
                ->values()
                ->map(function ($aml_uuid) {
                    return ['id' => $aml_uuid];
                })
                ->toArray();

            if (!empty($notify_data)) {
                $this->syncAml($notify_data);

                return true;
            }

            $this->info('notify data is empty');

            return true;
        }

        $model_type = $this->choice('Choose Application or Vendor?', [
            'Application',
            'Vendor',
        ]);

        switch ($model_type) {
            case 'Application':
                $this->askApplication();
                break;
            case 'Vendor':
                $this->askVendor();
                break;
        }

        return true;
    }

    private function syncAml(array $notifyData): bool
    {
        $this->internalService = app(InternalService::class);
        $amlUuids = array_column($notifyData, 'id');
        $amlUuidToApplicationMap = $this->internalService->getAmlUuidToApplicationMap($amlUuids);

        $this->internalService->amlNotify($notifyData, $amlUuidToApplicationMap);

        $this->info('notify success');

        return true;
    }

    private function askVendor()
    {
        $find_vendor_type = $this->choice('Choose find vendor uuid/id/application_vendor_uuid type?', [
            'id',
            'uuid',
            'application_vendor_uuid',
        ], 1);
        $is_confirm = false;

        while (empty($vendor) || !$is_confirm) {
            $vendor_uuid = $this->ask("vendor $find_vendor_type?");

            $vendor = Vendor::where($find_vendor_type, $vendor_uuid)->first();

            if (!empty($vendor)) {
                $this->info('vendor information');
                $this->info('------------------');
                $this->info('vendor uuid: '.$vendor->uuid);
                $this->info('vendor id: '.$vendor->id);
                $this->info('vendor name: '.$vendor->name);
                $this->info('------------------');

                $is_confirm = $this->confirm('Confirm?');
            }
        }

        $is_add_remit_info = $this->confirm('Do you want to sync aml remit info?');

        if ($is_add_remit_info) {
            $notify_data = BaseInformation::where('model_id', $vendor->id)
                ->where('model_type', 'vendor')
                ->groupBy('aml_uuid')
                ->get('aml_uuid')
                ->pluck('aml_uuid')
                ->filter()
                ->values()
                ->map(function ($aml_uuid) {
                    return ['id' => $aml_uuid];
                })
                ->toArray();

            if (!empty($notify_data)) {
                $this->syncAml($notify_data);

                return true;
            }

            $this->info('notify data is empty');
        }

        return true;
    }

    private function askApplication()
    {
        $find_application_type = $this->choice('Choose find application uuid/id type?', [
            'id',
            'uuid',
        ], 1);
        $is_confirm = false;

        while (empty($application) || !$is_confirm) {
            $payer_uuid = $this->ask("application $find_application_type?");

            $application = Application::where($find_application_type, $payer_uuid)->first();

            if (!empty($application)) {
                $this->info('application information');
                $this->info('------------------');
                $this->info('application uuid: '.$application->uuid);
                $this->info('application id: '.$application->id);
                $this->info('application name: '.$application->name);
                $this->info('------------------');

                $is_confirm = $this->confirm('Confirm?');
            }
        }

        $is_add_remit_info = $this->confirm('Do you want to sync aml remit info?');

        if ($is_add_remit_info) {
            $notify_data = BaseInformation::where('model_id', $application->id)
                ->where('model_type', 'application')
                ->groupBy('aml_uuid')
                ->get('aml_uuid')
                ->pluck('aml_uuid')
                ->filter()
                ->values()
                ->map(function ($aml_uuid) {
                    return ['id' => $aml_uuid];
                })
                ->toArray();

            if (!empty($notify_data)) {
                $this->syncAml($notify_data);

                return true;
            }

            $this->info('notify data is empty');
        }

        return true;
    }
}
