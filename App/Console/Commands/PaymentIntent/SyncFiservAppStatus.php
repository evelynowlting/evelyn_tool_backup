<?php

namespace App\Console\Commands\PaymentIntent;

use App\Enums\FiservOnboardStatusEnum;
use App\Events\FiservMerchantOnboardSuccessEvent;
use App\Models\PaymentIntentsMerchantInfo;
use App\Services\FiservService;
use Illuminate\Console\Command;

class SyncFiservAppStatus extends Command
{
    protected $signature = 'sync:fiserv_app_status';
    protected $description = 'Sync fiserv app onboard status';

    public function __construct(
        protected FiservService $fiservService,
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $paymentIntentsMerchantInfos = PaymentIntentsMerchantInfo::query()
            ->where('is_test', false)
            ->whereNotNull('app_urn')
            ->whereIn('onboard_status', [
                FiservOnboardStatusEnum::PENDING,
                FiservOnboardStatusEnum::IN_PROCESS,
            ])
            ->get();

        $token = $this->fiservService->login();
        $status = [];
        foreach ($paymentIntentsMerchantInfos as $paymentIntentsMerchantInfo) {
            $appStatusResponse = $this->fiservService->appStatusInquiry($paymentIntentsMerchantInfo->app_urn, $token);
            $merchantId = $appStatusResponse['merchantDetails']['tradingLocations'][0]['memberMID'];
            $terminalId = $appStatusResponse['merchantDetails']['tradingLocations'][0]['terminals'][0];
            $onboardStatus = $appStatusResponse['uwDecision'];
            if (in_array($onboardStatus, [
                FiservOnboardStatusEnum::DECLINED,
                FiservOnboardStatusEnum::FAILED,
            ])) {
                _owlPayLog('fiserv_onboard_failed', compact('appStatusResponse'), 'fiserv', 'error');
            }
            $paymentIntentsMerchantInfo->update([
                'merchant_id' => $merchantId,
                'terminal_id' => $terminalId,
                'onboard_status' => $onboardStatus,
            ]);
            if (FiservOnboardStatusEnum::APPROVED == $onboardStatus) {
                event(new FiservMerchantOnboardSuccessEvent($paymentIntentsMerchantInfo));
            }
            if (array_key_exists($onboardStatus, $status)) {
                ++$status[$onboardStatus];
            } else {
                $status[$onboardStatus] = 1;
            }
        }
        _owlPayLog('sync_fiserv_app_status', [
            'quried' => $paymentIntentsMerchantInfos->count(),
            'status' => $status,
        ], 'fiserv', 'info');
    }
}
