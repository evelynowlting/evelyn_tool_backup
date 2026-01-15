<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FiservOnboardTool extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fiserv:onboard_tool {--mode=none} {--token=test} {--message=hello}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Fiserv merchant boarding API';

    // @TODO: move to config
    private $url_base;
    private $user_id;
    private $password;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->url_base = config('payment_intent.bank.fiserv.onboard_url');
        $this->user_id = config('payment_intent.bank.fiserv.onboard_user_id');
        $this->password = config('payment_intent.bank.fiserv.onboard_password');
    }

    private function login()
    {
        $response = Http::post($this->url_base.'/boarding/auth/login', [
            'ain' => '2900000', // static
            'appVersionCode' => 65,  // static
            'appVersionName' => '10.3.2',  // static
            'pin' => $this->password,
            'source' => 'API', // static
            'terminalId' => $this->user_id,
        ]);

        return $response['data']['token'];
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
            case 'encrypt':
                $secret = $this->encrypt($this->option('message'));
                $this->line($secret);
                break;
            case 'decrypt':
                $plain = $this->decrypt($this->option('message'));
                $this->line($plain);
                break;
            case 'login':
                $token = $this->login();
                $this->line($token);
                break;
            default:
                $this->line("php artisan fiserv:onboard_tool --mode=encrypt --message='Hello World'");
                $this->line("php artisan fiserv:onboard_tool --mode=decrypt --message='NZh/IaKruxA7BWGb7SMZLg=='");
                $this->line('php artisan fiserv:onboard_tool --mode=login');
                break;
        }

        return 0;
    }
}
