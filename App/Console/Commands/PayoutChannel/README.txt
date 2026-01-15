麻煩Julian大大了

1. 到雲端硬碟 https://drive.google.com/drive/folders/1dG0IHPpG6o-PmVx5k5K2Uiak3qE2cbnC

2. 下載 testing_data 放到 App\Console\Commands\PayoutChannel\CRB

3. 檔案目錄
./visa_vda/response
./visa_vda/production
    ./visa_vda/production/019_visa_vda_production_tc_vda_hkd_002.json
    ./visa_vda/production/002_visa_vda_production_tc_vda_sepa_002.json
    ./visa_vda/production/010_visa_vda_production_tc_vda_cad_001.json
    ./visa_vda/production/005_visa_vda_production_tc_vda_sepa_005.json
    ./visa_vda/production/007_visa_vda_production_tc_vda_huf_002.json
    ./visa_vda/production/021_visa_vda_production_tc_vda_jpy_002.json
    ./visa_vda/production/016_visa_vda_production_tc_vda_pln_001.json
    ./visa_vda/production/015_visa_vda_production_tc_vda_sgd_002.json
    ./visa_vda/production/012_visa_vda_production_tc_vda_myr_001.json
    ./visa_vda/production/003_visa_vda_production_tc_vda_sepa_003.json
    ./visa_vda/production/004_visa_vda_production_tc_vda_sepa_004.json
    ./visa_vda/production/001_visa_vda_production_tc_vda_sepa_001.json
    ./visa_vda/production/014_visa_vda_production_tc_vda_sgd_001.json
    ./visa_vda/production/020_visa_vda_production_tc_vda_jpy_001.json
    ./visa_vda/production/018_visa_vda_production_tc_vda_hkd_001.json
    ./visa_vda/production/011_visa_vda_production_tc_vda_cad_002.json

    ./visa_vda/production/x_009_visa_vda_production_tc_vda_krw_002.json
    ./visa_vda/production/x_006_visa_vda_production_tc_vda_huf_001.json
    ./visa_vda/production/x_008_visa_vda_production_tc_vda_krw_001.json
    ./visa_vda/production/x_017_visa_vda_production_tc_vda_pln_002.json
    ./visa_vda/production/x_013_visa_vda_production_tc_vda_myr_002.json

4. 執行檔 

    VisaVDAProdUtil.php

每個檔案先打一次
php artisan visa:vda-prod-util validate_payout
再打 
php artisan visa:vda-prod-util send_payout

001_visa_vda_production_tc_vda_sepa_001.json
tc-vda-prod-sepa-001-20260107-01

002_visa_vda_production_tc_vda_sepa_002.json
tc-vda-prod-sepa-002-20260107-02

003_visa_vda_production_tc_vda_sepa_003.json
tc-vda-prod-sepa-003-20260107-03

004_visa_vda_production_tc_vda_sepa_004.json
tc-vda-prod-sepa-004-20260107-04

005_visa_vda_production_tc_vda_sepa_005.json
tc-vda-prod-sepa-005-20260107-05

007_visa_vda_production_tc_vda_huf_002.json
tc-vda-prod-huf-002-20260107-01

010_visa_vda_production_tc_vda_cad_001.json
tc-vda-prod-cad-001-20260107-01

011_visa_vda_production_tc_vda_cad_002.json
tc-vda-prod-cad-002-20260107-02

012_visa_vda_production_tc_vda_myr_001.json
tc-vda-prod-myr-001-20260107-01

014_visa_vda_production_tc_vda_sgd_001.json
tc-vda-prod-sgd-001-20260107-01

015_visa_vda_production_tc_vda_sgd_002.json
tc-vda-prod-sgd-002-20260107-02

016_visa_vda_production_tc_vda_pln_001.json
tc-vda-prod-pln-001-20260107-01

018_visa_vda_production_tc_vda_hkd_001.json
tc-vda-prod-hkd-001-20260107-01

019_visa_vda_production_tc_vda_hkd_002.json
tc-vda-prod-hkd-002-20260107-02

020_visa_vda_production_tc_vda_jpy_001.json
tc-vda-prod-jpy-001-20260107-01

021_visa_vda_production_tc_vda_jpy_002.json
tc-vda-prod-jpy-002-20260107-02
