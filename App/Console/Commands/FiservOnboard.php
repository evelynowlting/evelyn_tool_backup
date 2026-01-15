<?php

namespace App\Console\Commands;

use App\Services\FiservService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FiservOnboard extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fiserv:onboard {--mode=none} {--token=test} {--message=hello} {--urn=urn}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Fiserv merchant boarding API';

    // @TODO => move to config
    private $url_base = 'https://www.uat.fdmerchantservices.com/boardinggateway';
    private $user_id = 'owltingmmpfachk';
    private $institution_id = '29';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(private FiservService $fiservService)
    {
        parent::__construct();
        $this->user_id = config('payment_intent.bank.fiserv.onboard_user_id');
        $this->institution_id = config('payment_intent.bank.fiserv.onboard_institution_id');
    }

    private function createUrn($token)
    {
        // @TODO: get MerchantDetail Name and Business Name from request
        $body = [
          'appType' => 'API',
          'salesId' => $this->user_id,
          'keySelection' => [
              'boardingType' => 'NEWMID',
          ],
          'merchantDetails' => [
              'businessName' => 'Maras Test 01',
              'legalName' => 'Maras Test 01',
          ],
      ];

        $encrypted = $this->fiservService->encrypt(json_encode($body));

        $response = Http::withBody($encrypted, 'application/json')
          ->withHeaders(['Authorization' => $token])
          ->post($this->url_base.'/externalboarding/secure/app/create');

        return $this->fiservService->decrypt($response->body());
    }

    private function submitNewMID(string $appUrn, string $token)
    {
        // @TODO: mapping merchant data from request
        $body =
      [
          'appURN' => $appUrn,
          'appType' => 'API',
          'salesId' => $this->user_id,
          'institutionId' => $this->institution_id,
          'boardingType' => 'NEWMID',
          'merchantDetails' => [
            'businessName' => 'SET 8 Merch HKD',
            'legalName' => 'SET 8 Merch HKD',
            'businessType' => 'Private Limited',
            'registrationNo' => '200110991A',
            'fundingType' => 'HKD',
            'customerID' => '',
            'merchantSegment' => 'SMB',
            'countryofOrigin' => '',
            'taxDetails' => [
              'taxidType' => 'UEN',
              'taxidValue' => '202110994Z',
            ],
            'dateIncorporated' => '04-07-2022',
            'websiteURL' => 'https://www.testurl.com',
            'communicationEmail' => 'test@fiserv.com',
            'acceptanceType' => 'ECOM',
            'legalAddress' => [
              'unit' => '',
              'level' => '',
              'addressLine' => 'BLK 1008 NO. 26',
              'addressLine2' => '',
              'addressLine3' => '',
              'city' => 'Chai Wan',
              'pincode' => '000000',
              'state' => '',
              'country' => 'Hong Kong',
              'phone1' => '97910660',
              // 'phone2' => '',
              'email' => 'test@fiserv.com',
              'contactName' => 'Prerana',
            ],
            // 'geoLocation' => [
            //   'latitude' => '',
            //   'longitude' => '',
            // ],
          ],
          'tradingLocations' => [
            [
              'locationNo' => 1,
              'tradingName' => 'SET 3 Merch HKD',
              'tradingAddress' => [
                'unit' => '',
                'level' => '',
                'addressLine' => 'BLK 1008 NO. 26',
                'addressLine2' => '',
                'addressLine3' => '',
                'city' => 'Chai Wan',
                'pincode' => '000000',
                'state' => '',
                'country' => 'Hong Kong',
                'phone1' => '85785999',
                // 'phone2' => '',
                'email' => 'test@fiserv.com',
                'contactName' => 'test1',
              ],
              'packageId' => '15796',
              // 'cpvGroupId' => '',
              // 'regionalcpvGroupid' => '',
              // 'amexSE' => '',
              // 'dinersMID' => '',
              'terminals' => [
                [
                  'count' => 1,
                  // 'preferedDate' => '',
                  // 'preferedTime' => '',
                  'localTranCurr' => '999',
                ],
              ],
              'sameLocation' => null,
              // 'mid' => '',
              // 'leadsubgroupmid' => '',
              // 'subgroupmid' => '',
            ],
          ],
          'principalDetails' => [
            [
              'principalNo' => 1,
              'position' => 'director',
              'title' => 'MS',
              'firstName' => 'WEI',
              'lastName' => 'WANG',
              // 'middleName' => '',
              'dateOfBirth' => '14-09-1973',
              'nationality' => 'HK',
              'kycDetails' => [
                [
                  'kycCategory' => 'POI',
                  'identityType' => 'ID',
                  'identityValue' => '',
                  'identitymetaName1' => '',
                  'identitymetaValue1' => '',
                ],
                [
                  'kycCategory' => 'POA',
                  'identityType' => 'others',
                  'identityValue' => '',
                  'identitymetaName1' => '',
                  'identitymetaValue1' => '',
                ],
              ],
              'principalAddress' => [
                'unit' => '',
                'level' => '',
                'addressLine' => '10 ANSON ROAD # INTERNATIONA',
                'addressLine2' => '',
                'addressLine3' => '',
                'city' => 'Singapore',
                'pincode' => '000000',
                'state' => 'Singapore',
                'country' => 'Hong Kong',
                'phone1' => '98635016',
                // 'phone2' => '',
                'contactName' => 'WANG WEI',
              ],
            ],
          ],
          'uboDetails' => [
            [
              'serialNo' => 1,
              'title' => 'MR',
              'firstName' => 'TIM',
              'lastName' => 'CHAN',
              // 'middleName' => '',
              'dateOfBirth' => '03-09-1990',
              'shareholderPercentage' => 41.00,
              'nationality' => 'JP',
              'kycDetails' => [
                [
                  'kycCategory' => 'POI',
                  'identityType' => 'PASSPORT',
                  'identityValue' => 'J1684515T',
                  'identitymetaName1' => '',
                  'identitymetaValue1' => '',
                ],
              ],
              'uboAddress' => [
                'unit' => '',
                'level' => '',
                'addressLine' => 'jsdopifjj',
                'addressLine2' => '',
                'addressLine3' => '',
                'city' => 'Singapore',
                'pincode' => '000000',
                'state' => 'Singapore',
                'country' => 'hong kong',
                'phone1' => '12345678',
                // 'phone2' => '',
                'contactName' => 'TIM CHAN',
              ],
            ],
          ],
          'businessSummary' => [
            'briefSummary' => 'Selling all kinds of stuff',
            'mccCode' => '5813',
            'mccDescription' => 'Everything',
            'totalTurnover' => '6000000',
            'cardTurnover' => '6000000',
            'avgTicketAmt' => '100',
            'tranVolumeInternet' => '100',
            'tranVolumeMoto' => '0',
            'tranVolumeInStore' => '0',
            'deliveryDays0' => '0',
            'deliveryDays7' => '100',
            'deliveryDays14' => '0',
            'deliveryDays30' => '0',
            'deliveryDaysOver30' => '0',
            'tranTypeMagStrip' => '0',
            'tranTypeChip' => '100',
            'tranTypeKeyed' => '0',
            // 'creditSalesConsumer' => '',
            // 'creditSalesBusiness' => '',
          ],
      ];

        $encrypted = $this->fiservService->encrypt(json_encode($body));

        $response = Http::withBody($encrypted, 'application/json')
          ->withHeaders(['Authorization' => $token])
          ->post($this->url_base.'/externalboarding/api/submit');

        return $this->fiservService->decrypt($response->body());
    }

    private function appStatusInquiry($urn, $token)
    {
        $response = Http::withHeaders(['Authorization' => $token])
          ->get($this->url_base.'/externalboarding/api/applicationStatus?urn='.$urn);

        return $this->fiservService->decrypt($response->body());
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $mode = $this->option('mode');

        switch ($mode) {
            case 'create_urn':
                $result = $this->createUrn($this->option('token'));
                $this->line($result);
                break;
            case 'submit_new_MID':
                $result = $this->submitNewMID($this->option('urn'), $this->option('token'));
                $this->line($result);
                break;
            case 'app_status_inquiry':
                $result = $this->appStatusInquiry($this->option('urn'), $this->option('token'));
                $this->line($result);
                break;
            default:
            $this->line('php artisan fiserv:onboard --mode=create_urn --token=(login_token)');
            $this->line('php artisan fiserv:onboard --mode=submit_new_MID --token=(login_token) --urn=(urn)');
                break;
        }

        return 0;
    }
}
