<?php

namespace App\Console\Commands\Development;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class CheckRouteDescriptionTranslate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:route_i18n {--methods=} {--detail}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check route i18n';

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
        $routeCollection = Route::getRoutes();
        $missing_route_name = [];
        $langs = $this->getLangs();
        $methods = $this->option('methods');
        $methods = explode(',', $methods);

        foreach ($routeCollection as $value) {
            $route_name = $value->getName();
            $route_methods = $value->methods();

            foreach ($methods as $method) {
                if (in_array($method, $route_methods)) {
                    if (is_null($route_name)) {
                        $this->warn('[warning] missing route name:'.$value->uri());
                    } else {
                        foreach ($langs as $lang) {
                            $route_name_i18n = trans('route_name', [], $lang);

                            if (!is_array($route_name_i18n)) {
                                $missing_route_name[$lang][] = $route_name;
                            } else {
                                if (!isset($route_name_i18n[$route_name])) {
                                    $missing_route_name[$lang][] = $route_name;
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->warn('-----');

        foreach ($missing_route_name as $lang => $routes) {
            $this->warn("[warning] $lang missing ".count($routes).' routes i18n');
        }

        if ($this->option('detail')) {
            foreach ($missing_route_name as $lang => $routes) {
                foreach ($routes as $missing_route_name) {
                    $this->warn('missing route '.$lang.' name:'.$missing_route_name);
                }
            }
        }
    }

    private function getLangs()
    {
        $langs = [];

        $lang_path = resource_path('lang');

        $lang_folder = scandir($lang_path);

        foreach ($lang_folder as $dir) {
            if (is_dir($lang_path.'/'.$dir) && false === strpos('.', $dir) && false === strpos('..', $dir)) {
                $langs[] = $dir;
            }
        }

        return $langs;
    }
}
