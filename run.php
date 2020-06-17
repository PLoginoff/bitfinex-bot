<?php

require_once 'vendor/autoload.php';

use Lin\Bitfinex\Bitfinex;

$config   = require 'config.php';
$bitfinex = new Bitfinex($config['key'], $config['secret']);

//Place an Order
try {
    $result=$bitfinex->order()->postSubmit([
        //'cid'=>'',
        'type'      => 'LIMIT',
        'symbol'    => 'tBTCUSD',
        'price'     => '1000',
        'amount'    => '0.001',//Amount of order (positive for buy, negative for sell)
    ]);
    print_r($result);
}catch (\Exception $e){
    print_r(json_decode($e->getMessage(),true));
}
