<?php

namespace App\Console\Commands;

use App\Enums\WalletBoundStatusEnum;
use App\Events\Platform\WalletBoundEvent;
use App\Services\OwltingWalletService;
use App\Services\PlatformService;
use Illuminate\Console\Command;
use Illuminate\Http\Response;
use owlting\sso\Exceptions\SSOException;
use owlting\sso\OwltingSSO;

class PlatformWalletBound extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'platform:wallet_bound';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'platform wallet bound';
    /**
     * @var PlatformService
     */
    private $platformService;

    /**
     * @var OwltingSSO
     */
    private $owltingSSO;

    /**
     * @var OwltingWalletService
     */
    private $owltingWalletService;

    /**
     * Create a new command instance.
     */
    public function __construct(PlatformService $platformService, OwltingSSO $owltingSSO, OwltingWalletService $owltingWalletService)
    {
        parent::__construct();
        $this->platformService = $platformService;
        $this->owltingSSO = $owltingSSO;
        $this->owltingWalletService = $owltingWalletService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $platforms = $this->platformService->getPlatformsByWalletStatus(WalletBoundStatusEnum::BINDING);

        foreach ($platforms as $platform) {
            $tempToken = $this->owltingSSO->getTempToken($platform->owlting_uuid);

            throw_if(!isset($tempToken['data']['tempToken']) || !isset($tempToken['data']['expireAt']), new SSOException('get sso temp token failed.', Response::HTTP_BAD_REQUEST));

            $tempAccessSSOToken = $tempToken['data']['tempToken'];

            $this->owltingWalletService->getWalletServiceProfile($platform->owlting_uuid, $tempAccessSSOToken);

            $owltingAmlMeta = $this->owltingWalletService->getAmlMeta($platform->owlting_uuid);

            if (!empty($owltingAmlMeta)) {
                event(new WalletBoundEvent($platform));
            }
        }
    }
}
