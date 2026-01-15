<?php

namespace App\Console\Commands\PayoutChannel;

use App\Enums\PayoutChannel\CrossBorderPayoutEnum;
use App\Enums\PayoutChannel\DomesticPayoutEnum;
use App\Exceptions\HttpException\EmptyException;
use App\PayoutGateways\Contracts\B2BPayoutGatewaySyncFees;
use App\Services\ApplicationService;
use App\Services\PayoutGatewayService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SyncPayoutGatewayFee extends Command
{
    protected $signature = 'sync:payout_gateway_fees
                            {--application_uuid= : specify application_uuid}
                            {--payout_gateway=* : Which payout_gateway want to sync. }
                            {--is_test=0 : is test mode or not}
                            ';

    protected $description = 'Sync application payout gateway fee.';

    public function __construct(
        private ApplicationService $applicationService,
        private PayoutGatewayService $payoutGatewayService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $needSyncPayoutGateways = $this->option('payout_gateway', []);
        $isTest = $this->option('is_test');

        $payoutGatewayEnumList = array_values(array_merge(CrossBorderPayoutEnum::toArray(), DomesticPayoutEnum::toArray()));
        $diff = array_diff($needSyncPayoutGateways, $payoutGatewayEnumList);
        throw_if(!empty($diff), new InvalidArgumentException('Argument payout_gateway not support: '.implode(', ', $diff).".\n enum: ".implode(', ', $payoutGatewayEnumList)));

        if (empty($needSyncPayoutGateways)) {
            $needSyncPayoutGateways = $payoutGatewayEnumList;
        }

        $applicationUuid = $this->option('application_uuid');

        if ($applicationUuid) {
            $application = $this->applicationService->getByUuid($applicationUuid);
            throw_if(empty($application), new EmptyException("Application $applicationUuid not found."));
            $applications = collect([$application]);
        } else {
            $applications = $this->applicationService->getByIsTest($isTest);
        }

        $applicationChunks = $applications->chunk(50);
        foreach ($applicationChunks as $applications) {
            $applicationIds = $applications->pluck('id')->toArray();
            // get existed payout gateway
            $applicationIdToExistedPayoutGatewaysMap = $this->payoutGatewayService->getByApplicationIdsPayoutGateways($applicationIds, $isTest, $needSyncPayoutGateways, ['application_id', 'gateway'])
                ->groupBy('application_id')->map(function ($payoutGateways) {
                    return $payoutGateways->unique('gateway')->pluck('gateway');
                })->toArray();

            foreach ($applications as $application) {
                $existedPayoutGateways = $applicationIdToExistedPayoutGatewaysMap[$application->id] ?? [];
                $payoutGateways = array_intersect($existedPayoutGateways, $needSyncPayoutGateways);
                $this->info('======================== '.$application->uuid.' ========================');
                foreach ($payoutGateways as $payoutGateway) {
                    $this->info("Start - sync $payoutGateway fees");
                    $payoutGatewayInstance = $this->payoutGatewayService->getPayoutGatewayInstance($application, $payoutGateway);
                    if (!($payoutGatewayInstance instanceof B2BPayoutGatewaySyncFees)) {
                        $this->info("End - $payoutGateway not implement B2BPayoutGatewaySyncFees interface, skip it.");
                        continue;
                    }

                    $resultMessage = $payoutGatewayInstance->syncPayoutGatewayFees();
                    $this->info("End - $payoutGateway fees result: $resultMessage");
                }
            }
        }

        return 0;
    }
}
