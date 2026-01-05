<?php

namespace App\Console\Commands\evelyn;

use Illuminate\Console\Command;

class CathayBankXmlTool extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cathay:xml-tool
                            {--mode=""}
                            {--file=""}
                            ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
        $mode = $this->option('mode');
        $file = $this->option('file');

        $modes = [
            'check_rmt_details',
            'check_rmt_upload',
        ];
        $mode = strtolower(trim($this->option('mode')));

        if (!in_array($mode, $modes)) {
            $this->error('Please input correct mode.');

            $this->info('For check RMT details, please enter --mode=check_rmt_details');
            $this->info('For check RMT upload progress, please enter --mode=check_rmt_upload');

            return;
        }

        $file = trim($this->ask('Please input the file name. ex: cathay_debug_queryRMT_20231213_1117_51420440.xml'));
        // $file = 'cathay_debug_queryRMT_20230919_1046_48493886.xml';
        // $file =  'cathay_debug_queryRMT_20230628_1035_46045175.xml'; //OK
        // $file = 'cathay_debug_queryRMT_20230920_1750_48493886.xml';
        // $file = 'cathay_debug_queryUpload_20230919_1304.xml';
        $file = __DIR__.'/'.$file;

        $xml = \file_get_contents($file);

        // if (!mb_check_encoding($xml, 'Big5')) {
        //     $this->warn('The XML is not Big5 encoding. Please check the encoding.');

        //     return;
        // }
        $dom = new \DOMDocument('1.0', 'Big5');
        $dom->loadXml($xml);

        if ('check_rmt_details' == $mode) {
            $xp = new \DOMXPath($dom);
            $data_count = $xp->evaluate('count(//BODY//DATAS/DATA)');

            if (empty($data_count)) {
                $this->line('No data');
            }

            $this->warn('******** 開始處理匯款檔案明細 ********');
            $this->line('=== 統計 ===');
            $this->line('總交易筆數='.$data_count);

            $xpath = '//BODY//DATAS/DATA';
            $data_node = $this->_convertXMLNodeToArray($xp, $xpath);

            $import_succeed = [];
            $txn_succeed = [];
            $others = [];
            foreach ($data_node as $node) {
                if ('匯出成功' == $node['Error_Msg']) {
                    $import_succeed[] = $node;
                } elseif ('交易成功' == $node['Error_Msg']) {
                    $txn_succeed[] = $node;
                } else {
                    $others[] = $node;
                }
            }

            $this->line('匯出成功='.count($import_succeed));
            $this->line('交易成功='.count($txn_succeed));
            $this->line('其他= '.count($others));

            // 列出所有錯誤訊息 ＆ 列出所有錯誤代碼
            if (empty($others)) {
                return;
            }

            // var_dump($others);
            $this->line(string: '=== 有特殊 Error_Msg 和 Error_Code (排除交易成功 & 匯出成功) 共 '.count($others).' 筆 ===');
            $this->_dumpResultToFile($others, 'cathay_debug_queryRMT');
        }

        if ('check_rmt_upload' == $mode) {
            $xp = new \DOMXPath($dom);

            $xpath = '//BODY//DATAS/DATA';
            $data_node = $this->_convertXMLNodeToArray($xp, $xpath);

            if (empty($data_node)) {
                $this->line('No DATA');
            }

            $upload_progress_list = array_unique($data_node, SORT_REGULAR);

            $this->warn('******** 開始處理匯款檔案上傳狀態 ********');
            $this->line('=== 統計 ===');
            $this->line('總上傳筆數='.count($data_node));
            $this->line('去除重覆後筆數='.count($upload_progress_list));

            // 0000 檔案待建檔 Files are ready to be processed
            // 0001 檔案尚處理中 Files are still processing
            // 0002 檔案檢核中 Files are still checking
            // 0003 建檔完成 Success
            // 0004 檔案已刪除 Files have been deleted
            // 0005 自動化建檔失敗 File creation failed

            $this->line('=== 上傳進度  共 '.count($upload_progress_list).' 筆 ===');
            $this->_dumpResultToFile($upload_progress_list, 'cathay_debug_queryUpload');
        }
    }

    private function _dumpResultToFile($content, $file_output)
    {
        $datetime = now()->timezone('Asia/Taipei')->format('Ymd_Hi');
        $file_name = __DIR__.'/'.$file_output.'_errors_'.$datetime.'.txt';
        file_put_contents($file_name, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line('結果輸出於檔案 '.$file_name);
    }

    private function _convertXMLNodeToArray($xp, $xpath)
    {
        $datas = $xp->query($xpath);

        $data_node = [];
        foreach ($datas as $idx => $data) {
            foreach ($data->childNodes as $child) {
                // echo "Node: {$child->nodeName}, Value: {$child->textContent}\n";

                if ('#text' != $child->nodeName) {
                    $data_node[$idx][$child->nodeName] = $child->textContent;
                }

                // if ('Error_Msg' == $child->nodeName) {
                // $a = mb_detect_encoding($child->textContent);
                // var_dump("encoding " . $a);
                // }
            }
        }

        return $data_node;
    }
}
