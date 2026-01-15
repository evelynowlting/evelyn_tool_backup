<?php

namespace App\Console\Commands\Policy;

use App\Events\Policy\NoticeApplicationOwnerNewVersionPolicyEvent;
use App\Generators\OwlPayTokenGenerator;
use App\Models\Application;
use App\Models\PayoutGateway;
use App\Models\Policy;
use App\Services\ApplicationService;
use App\Services\PolicyService;
use Illuminate\Console\Command;
use Mavinoo\Batch\BatchFacade as Batch;

class SyncPolicyFromConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'policy:sync_from_config {--application_id=} {--type=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync policy from config';

    /**
     * @example [application1->id, application2->id]
     *
     * @var array
     */
    protected $newVersionPolicyApplicationId = [];

    /**
     * @example [
     *      application1->id => [policy1->type, policy2->type],
     *      application2->id => [policy2->type]
     * ]
     *
     * @var array
     */
    protected $newVersionPolicyTypeByApplicationid = [];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private ApplicationService $applicationService,
        private PolicyService $policyService,
        private OwlPayTokenGenerator $owlPayTokenGenerator
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
        $application_id = $this->option('application_id');
        $type = $this->option('type');

        switch ($type) {
            case 'saas':
            case 'extensionInstalledSaaS':
                $this->syncSaaSPolicyFromConfig($application_id, $type);
                break;
        }
    }

    private function syncSaaSPolicyFromConfig($application_id = null, $type = '')
    {
        $policiesInConfig = config('policy')[$type];

        foreach ($policiesInConfig as $country => $policyInConfig) {
            foreach ($policyInConfig as $row) {
                $inputApplicationIds = [];

                if (empty($application_id)) {
                    if ('TW' == $country) {
                        $applications = Application::where('country_iso', 'TW')->get();
                    } else {
                        // 目前是除台灣全部都是拿 US 的
                        $applications = Application::where('country_iso', '!=', 'TW')->get();
                    }
                    $applicationsIds = $applications->pluck('id')->unique()->toArray();
                } else {
                    $application = Application::find($application_id);

                    // TW 的公司只能綁定 config('policy.saas.TW') 的 policies; 其它國家的全都綁定 config('policy.saas.US')
                    if (
                        ('TW' == $country && $application->country_iso != $country) ||
                        ('TW' != $country && 'TW' == $application->country_iso)
                    ) {
                        continue;
                    } else {
                        $applicationsIds = [$application_id];
                    }
                }

                $inputApplicationIds = $applicationsIds;

                $applicationsIds = PayoutGateway::whereIn('application_id', $applicationsIds)
                                                ->whereIn('gateway', $row['payout_gateways'])
                                                ->pluck('application_id')
                                                ->unique()
                                                ->toArray();

                if (empty($applicationsIds)) {
                    // 原本流程為安裝好付款擴充途徑時，會 trigger 此 command，所以 table:payout_gateways 裡一定會有紀錄；現改成一建立公司則 trigger 此 command，故
                    $applicationsIds = $inputApplicationIds;
                }

                $applicationPolicies = $this->policyService->getPoliciesByType($row['type'], $row['version']);

                $applicationIdsInPolicy = $applicationPolicies->pluck('application_id')->unique()->toArray();

                $shouldInsertApplicationIds = array_diff($applicationsIds, $applicationIdsInPolicy);

                foreach ($shouldInsertApplicationIds as $applicationId) {
                    $policy = $this->policyService->fetchFormerPolicyByApplicationIdAndType($applicationId, $row['type']);

                    if (!empty($policy)) {
                        // 若 table 裡含有此公司同 type 的 policy，代表目前上架的 policy 為最新版，則需要以 email 通知 owner
                        if (!in_array($applicationId, $this->newVersionPolicyApplicationId)) {
                            $this->newVersionPolicyApplicationId[] = $applicationId;
                        }

                        if (!isset($this->newVersionPolicyTypeByApplicationid[$applicationId])) {
                            $this->newVersionPolicyTypeByApplicationid[$applicationId] = [];
                        }
                        $this->newVersionPolicyTypeByApplicationid[$applicationId][] = $row['type'];
                    }
                }

                $insertData = array_map(function ($applicationId) use ($row) {
                    $hash_data = hash('sha256', $applicationId.'_'.time().random_int(0, 999999));

                    $uuid = $this->owlPayTokenGenerator->create('policy', $hash_data);

                    return [
                        'application_id' => $applicationId,
                        'uuid' => $uuid,
                        'source_policy_name' => $row['source_policy_name'],
                        'name' => $row['name'],
                        'url' => $row['url'],
                        'content' => $row['content'],
                        'version' => $row['version'],
                        'is_application_agreement' => $row['is_application_agreement'],
                        'type' => $row['type'],
                    ];
                }, $shouldInsertApplicationIds);

                $this->removeExpiredAndUnConfirmPolicies($row, $shouldInsertApplicationIds);

                if (!empty($insertData)) {
                    if ('testing' == env('APP_ENV')) {
                        // fixing sqlite General error: 1 near "s": syntax error
                        Policy::insert($insertData);
                    } else {
                        $insertDataInput = array_values($insertData);

                        if (isset($insertData[0])) {
                            $batchInsertResult = Batch::insert(new Policy(), array_keys($insertData[0]), $insertDataInput);
                        }

                        $this->info('[Sync Policy from Config] sync result count: '.($batchInsertResult['totalRows'] ?? 0));
                    }
                } else {
                    $this->info('[Sync Policy from Config] sync result count: 0');
                }
            }
        }

        if (!empty($this->newVersionPolicyApplicationId)) {
            foreach ($this->newVersionPolicyApplicationId as $applicationId) {
                $owner = $this->applicationService->getOwnerByApplicationId($applicationId);

                $policiesArr = [];
                foreach ($this->newVersionPolicyTypeByApplicationid[$applicationId] as $policyType) {
                    $policy = $this->policyService->getLatestPolicyByType($policyType);
                    $policiesArr[] = $policy;
                }

                if (!empty($policiesArr)) {
                    event(new NoticeApplicationOwnerNewVersionPolicyEvent($policiesArr, $owner));
                }
            }
        }
    }

    /**
     * Delete expired and Not confirmed yet policies.
     *
     * @return void
     */
    private function removeExpiredAndUnConfirmPolicies(array $policyInConfig, array $shouldInsertApplicationIds)
    {
        $deletedCount = 0;
        if (!empty($shouldInsertApplicationIds)) {
            $shouldDeletePolicies = Policy::whereIn('application_id', $shouldInsertApplicationIds)
                ->where('type', $policyInConfig['type'])
                ->where('version', '!=', $policyInConfig['version'])
                ->with(['platforms'])
                ->get();

            foreach ($shouldDeletePolicies as $shouldDeletePolicy) {
                if ($shouldDeletePolicy->platforms->isEmpty()) {
                    $shouldDeletePolicy->delete();
                    ++$deletedCount;
                }
            }
        }
        $this->info('[Sync Policy from Config] deleted count: '.$deletedCount);
    }
}
