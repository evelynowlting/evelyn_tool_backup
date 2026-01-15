<?php

namespace App\Console\Commands;

use App\Enums\AmlApplicantEnum;
use App\Enums\AmlCreditDebitEnum;
use App\Enums\AmlMarkTypeEnum;
use App\Enums\AmlPostVersionEnum;
use App\Enums\AmlRedirectTypeEnum;
use App\Exceptions\HttpException\AMLException;
use App\Models\AmlMark;
use App\Models\Vendor;
use App\Repositories\BaseInformationRepository;
use App\Services\AMLService;
use App\Services\InternalService;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Mavinoo\Batch\BatchFacade as Batch;

class BaseInformationImportToAmlSystem extends Command
{
    /**
     * The name and signature of the console command.
     *
     * aml_post_version
     * 完整版 complete
     * 簡易版 simple
     * 草稿版 draft
     *
     * @var string
     */
    protected $signature = 'import:base_information_to_aml_system {aml_post_version}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Base information import to aml system';

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
     * @return bool
     *
     * @throws \Box\Spout\Common\Exception\IOException
     * @throws \Box\Spout\Common\Exception\UnsupportedTypeException
     * @throws \Box\Spout\Reader\Exception\ReaderNotOpenedException
     */
    public function handle()
    {
        $aml_post_version = $this->argument('aml_post_version');

        if (!in_array($aml_post_version, AmlPostVersionEnum::toArray())) {
            $this->error("[ERROR] Aml post version input 'complete' or 'simple' or 'draft'");

            return 0;
        }

        $file = storage_path('app/temp_aml/owlpay_hotels.xlsx');

        if (!file_exists($file)) {
            $this->info('file does not exist');

            return true;
        }

        $reader = ReaderEntityFactory::createReaderFromFile($file);

        $reader->open($file);

        $sheets = $reader->getSheetIterator();

        $aml_import_contents = [];

        foreach ($sheets as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                // 欄位要依照當時給的 excel 表格做調整
                $cells = $row->getCells();

                $applicant = isset($cells[0]) ? $cells[0]->getValue() : null;
                $vendor_uuid = isset($cells[1]) ? $cells[1]->getValue() : null;
                $remit_info_id = isset($cells[2]) ? $cells[2]->getValue() : null;
                $hotel_id = isset($cells[3]) ? $cells[3]->getValue() : null;
                $companyName = isset($cells[4]) ? $cells[4]->getValue() : null;
                $companyCountry = isset($cells[5]) ? $cells[5]->getValue() : null;
                $businessAddressCity = isset($cells[6]) ? $cells[6]->getValue() : null;
                $businessAddressArea = isset($cells[7]) ? $cells[7]->getValue() : null;
                $businessAddress = isset($cells[8]) ? $cells[8]->getValue() : null;
                $companyPhoneCode = isset($cells[9]) ? $cells[9]->getValue() : null;
                $companyPhoneNumber = isset($cells[10]) ? $cells[10]->getValue() : null;
                $companyEmail = isset($cells[11]) ? $cells[11]->getValue() : null;
                $companyId = isset($cells[12]) ? $cells[12]->getValue() : null;
                $customName = isset($cells[13]) ? $cells[13]->getValue() : null;
                $bankCountry = isset($cells[14]) ? $cells[14]->getValue() : null;
                $bankCode = isset($cells[15]) ? $cells[15]->getValue() : null;
                $branchCode = isset($cells[16]) ? $cells[16]->getValue() : null;
                $accountName = isset($cells[17]) ? $cells[17]->getValue() : null;
                $account = isset($cells[18]) ? $cells[18]->getValue() : null;

                $businessAddress = $this->transferBusinessAddress($businessAddress);
                $companyPhoneNumber = $this->transferCompanyPhoneNumber($companyPhoneNumber);
                $branchCode = $this->transferBranchCode($bankCode, $branchCode);

                // 目前是做簡易版，所以不用傳那麼多欄位給 Aml
                if (in_array($companyCountry, ['tw', 'TW'])) {
                    if ('個人' == $applicant) {
                        $individual_aml_data = [
                            'vendor_uuid' => $vendor_uuid,
                            'applicant' => AmlApplicantEnum::INDIVIDUAL,
                            'lastName' => '',
                            'firstName' => $accountName,
                            'gender' => '',
                            'identity' => '',
                            'birthday' => '',
                            'representativeCountry' => 'TW',
                            'city' => $businessAddressCity,
                            'area' => $businessAddressArea,
                            'address' => $businessAddress,
                            'phoneCode' => 'TW',
                            'phoneNumber' => $companyPhoneNumber,
                            'email' => $companyEmail,
                            'customName' => $customName,
                            'currency' => 'TWD',
                            'bankCountry' => $bankCountry,
                            'bankCode' => $bankCode,
                            'branchCode' => $branchCode,
                            'accountName' => $accountName,
                            'account' => $account,
                        ];

                        $aml_import_contents[] = match ($aml_post_version) {
                            AmlPostVersionEnum::COMPLETE, AmlPostVersionEnum::DRAFT => $individual_aml_data,
                            AmlPostVersionEnum::SIMPLE => Arr::only($individual_aml_data, [
                                'vendor_uuid',
                                'applicant',
                                'lastName',
                                'firstName',
                                'representativeCountry',
                                'currency',
                                'bankCountry',
                                'bankCode',
                                'branchCode',
                                'accountName',
                                'account',
                            ])
                        };
                    }

                    if ('公司' == $applicant) {
                        $company__aml_data = [
                            'vendor_uuid' => $vendor_uuid,
                            'applicant' => AmlApplicantEnum::COMPANY,
                            'companyName' => $companyName,
                            'companyCountry' => 'TW',
                            'businessAddressCity' => $businessAddressCity,
                            'businessAddressArea' => $businessAddressArea,
                            'businessAddress' => $businessAddress,
                            'companyPhoneCode' => 'TW',
                            'companyPhoneNumber' => $companyPhoneNumber,
                            'companyEmail' => $companyEmail,
                            'companyId' => $companyId,
                            'customName' => $customName,
                            'currency' => 'TWD',
                            'bankCountry' => $bankCountry,
                            'bankCode' => $bankCode,
                            'branchCode' => $branchCode,
                            'accountName' => $accountName,
                            'account' => $account,
                        ];

                        $aml_import_contents[] = match ($aml_post_version) {
                            AmlPostVersionEnum::COMPLETE, AmlPostVersionEnum::DRAFT => $company__aml_data,
                            AmlPostVersionEnum::SIMPLE => Arr::only($company__aml_data, [
                                'vendor_uuid',
                                'applicant',
                                'companyName',
                                'currency',
                                'bankCountry',
                                'bankCode',
                                'branchCode',
                                'accountName',
                                'account',
                            ])
                        };
                    }
                }
            }
        }

        $reader->close();

        // insert aml data to aml system
        if (!empty($aml_import_contents)) {
            $this->info('aml import contents is exist');
            $this->info('--------------------------------------');

            $this->importAmlDataToAmlSystem($aml_import_contents);

            return true;
        }

        $this->info('aml import contents data is empty');

        return true;
    }

    /**
     * 地址長度超過 100，帶前面 100 的字串.
     *
     * @param $businessAddress
     */
    public function transferBusinessAddress($businessAddress): ?string
    {
        if (strlen($businessAddress) > 100) {
            return mb_substr($businessAddress, 0, 100);
        }

        return $businessAddress;
    }

    /**
     * 電話號碼長度超過 15 帶空字串.
     *
     * @param $companyPhoneNumber
     */
    public function transferCompanyPhoneNumber($companyPhoneNumber): ?string
    {
        if ((strlen($companyPhoneNumber) > 15) || (!preg_match('/[0-9]+$/', $companyPhoneNumber))) {
            $companyPhoneNumber = '';
        }

        return $companyPhoneNumber;
    }

    /**
     * 1. 郵局 0021
     * 2. 七碼分行號碼抓後四碼結果.
     *
     * @param $bankCode
     * @param $branchCode
     */
    public function transferBranchCode($bankCode, $branchCode): ?string
    {
        if ('700' == $bankCode) {
            return '0021';
        }

        if (7 == strlen($branchCode)) {
            return substr($branchCode, -4, 4);
        }

        return $branchCode;
    }

    /**
     * 匯入資料進入 Aml 系統.
     *
     * @param $aml_import_contents
     */
    public function importAmlDataToAmlSystem($aml_import_contents): bool
    {
        /* @var AMLService $amlService */
        $amlService = app(AMLService::class);

        $user_created_list = [];

        $amlUuidToApplicationMap = [];

        $failed_list = [];

        $chunk_aml_import_contents = array_chunk($aml_import_contents, 400);

        $aml_post_version = $this->argument('aml_post_version');

        foreach ($chunk_aml_import_contents as $chunk_aml_import_content_list) {
            $this->info('ready to send import contents to aml');
            $this->info('--------------------------------------');

            $vendors = Vendor::query()
                ->with(['application'])
                ->whereIn('uuid', data_get($chunk_aml_import_content_list, '*.vendor_uuid'))
                ->get()
                ->keyBy('uuid');

            foreach ($chunk_aml_import_content_list as $aml_import_content) {
                try {
                    // 簡易版，資料完整打 user create
                    // 草稿版，資料不完整打 draft user create
                    // 簡易版 channel 為 simpleFiatTW

                    $application = $vendors->get($aml_import_content['vendor_uuid'])?->application;
                    $amlService->setApplication($application);
                    $user_created = match ($aml_post_version) {
                        AmlPostVersionEnum::COMPLETE => $amlService->postUserCreate(
                            externalId: $aml_import_content['vendor_uuid'],
                            idType: 'external',
                            companyCountry: 'TW',
                            channel: AmlRedirectTypeEnum::CATHAY,
                            targetCountry: 'TW',
                            creditDebit: AmlCreditDebitEnum::DEBIT,
                            applicant: $aml_import_content['applicant'],
                            aml_data: $aml_import_content,
                            parentName: $vendors->get($aml_import_content['vendor_uuid'])?->application?->name,
                        ),
                        AmlPostVersionEnum::SIMPLE => $amlService->postUserCreate(
                            externalId: $aml_import_content['vendor_uuid'],
                            idType: 'external',
                            companyCountry: 'TW',
                            channel: AmlRedirectTypeEnum::SIMPLE_FIAT_TW,
                            targetCountry: 'TW',
                            creditDebit: AmlCreditDebitEnum::DEBIT,
                            applicant: $aml_import_content['applicant'],
                            aml_data: $aml_import_content,
                            parentName: $vendors->get($aml_import_content['vendor_uuid'])?->application?->name,
                        ),
                        AmlPostVersionEnum::DRAFT => $amlService->postDraftUserCreate(
                            externalId: $aml_import_content['vendor_uuid'],
                            idType: 'external',
                            companyCountry: 'TW',
                            channel: AmlRedirectTypeEnum::CATHAY,
                            targetCountry: 'TW',
                            creditDebit: AmlCreditDebitEnum::DEBIT,
                            applicant: $aml_import_content['applicant'],
                            aml_data: $aml_import_content,
                            parentName: $vendors->get($aml_import_content['vendor_uuid'])?->application?->name,
                        ),
                    };

                    $user_created_list[] = [
                        'id' => $user_created['paymentMethodId'],
                    ];

                    $amlUuidToApplicationMap[] = [
                        $user_created['paymentMethodId'] => $application,
                    ];
                } catch (AMLException $e) {
                    $failed_list[] = $aml_import_content['vendor_uuid'];

                    _owlPayLog('aml_import_request_failed', [
                        'vendor_uuid' => $aml_import_content['vendor_uuid'],
                        'applicant' => $aml_import_content['applicant'],
                        'error_message' => $e->getMessage(),
                        'errors' => $e->getAttributes(),
                        'code' => $e->getCode(),
                    ], 'aml', 'error');

                    $this->info('aml import failed');
                    $this->info('--------------------------------------');
                }
            }

            // ThrottleRequestsException:Too Many Attempts.
            sleep(60);
        }

        _owlPayLog('aml_failed_vendor_uuids', $failed_list, 'aml');

        if (!empty($user_created_list)) {
            $this->info('aml import success');
            $this->info('--------------------------------------');

            // sync aml base information
            $this->syncAmlByAmlUUIDs($user_created_list, $amlUuidToApplicationMap);

            // 建立標記 aml mark
            $this->createAmlMark($user_created_list);

            return true;
        }

        $this->info('notify data is empty');

        return true;
    }

    /**
     * 同步 Aml 資料回 OwlPay.
     *
     * @param $user_created_list
     */
    public function syncAmlByAmlUUIDs($user_created_list, array $amlUuidToApplicationMap): bool
    {
        /* @var InternalService $internalService */
        $internalService = app(InternalService::class);

        $chunk_user_created_list = array_chunk($user_created_list, 400);

        $aml_success_notify_list = [];

        foreach ($chunk_user_created_list as $notify_data) {
            try {
                $aml_success_notify_list[] = $internalService->amlNotify($notify_data, $amlUuidToApplicationMap);

                $this->info('notify success');
                $this->info('--------------------------------------');

                _owlPayLog('aml_success_aml_uuids', array_column($notify_data, 'id'), 'aml');
            } catch (AMLException $e) {
                _owlPayLog('aml_api_request_failed', [
                    'notify_data' => $notify_data,
                    'error_message' => $e->getMessage(),
                    'errors' => $e->getAttributes(),
                    'code' => $e->getCode(),
                ], 'aml', 'error');

                $this->info('notify failed');
                $this->info('--------------------------------------');
            }
        }

        _owlPayLog('aml_success_notify_list', $aml_success_notify_list, 'aml');

        return true;
    }

    private function createAmlMark($user_created_list)
    {
        $aml_uuids = Arr::flatten($user_created_list);

        /* @var BaseInformationRepository $baseInformationRepository */
        $baseInformationRepository = app(BaseInformationRepository::class);

        $base_informations = $baseInformationRepository->getByAmlUuids($aml_uuids);

        foreach ($base_informations as $base_information) {
            $vendor_id = $base_information->model_id;
            $vendor = $base_information->modelable;

            if (!empty($vendor)) {
                $insert_data[] = [
                    'application_id' => $vendor->application?->id,
                    'model_id' => $vendor_id,
                    'model_type' => AmlRedirectTypeEnum::VENDOR,
                    'type' => AmlMarkTypeEnum::CREATED,
                    'currency' => $base_information->currency,
                    'payout_gateway' => $base_information->payout_gateway,
                ];
            }
        }

        $created = false;

        if (!empty($insert_data)) {
            $created = Batch::insert(new AmlMark(), array_keys($insert_data[0]), $insert_data);

            $this->info('aml mark created success');
            $this->info('--------------------------------------');

            return $created;
        }

        $this->info('aml mark created failed');
        $this->info('--------------------------------------');

        return $created;
    }
}
