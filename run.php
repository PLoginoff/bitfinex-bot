#!/usr/bin/env php
<?php

/*
$ ./run.php "BTCUSD, buy, 1"
tBTCUSD 0.00085807
$ ./run.php "BTCUSD, sell, 1"
tBTCUSD -0.00087828
*/

require_once 'vendor/autoload.php';

use Lin\Bitfinex\Bitfinex;

$config   = require 'config.php';
$bitfinex = new Bitfinex($config['key'], $config['secret']);

$signal = $argv[1] ?? null;

if (!$signal) {
    $signal = trim(file_get_contents('php://input'));
}

if (!$signal) {
    exit('php run.php "BTCUSD, sell, 1"\n');
}

[$ticket_, $dir_, $amount_] = explode(',', $signal);

//$signal = explode(' ','BTCUSD 1');

$ticket = 't'. trim($ticket_);
$dir    = trim($dir_);
$amount = intval(trim($amount_));

if ($dir === 'sell') {
    $amount = -1 * $amount;
}

//Place an Order
try {
    $rate_ = $bitfinex->calc()->postTradeAvg(['symbol' => $ticket, 'amount' => 1,]);
    $rate = $rate_[0];

    $orderAvail_ = $bitfinex->account()->postCalcOrderAvail([
        'symbol'    => $ticket,
        'dir'       => $dir === 'sell' ? -1 : 1,
        'type'      => 'MARGIN',
        'rate'      => $rate,
    ]);

    $orderAvail = $orderAvail_[0];

    $orderAmount = ($orderAvail / 100) * abs($amount) * 10;

    $log = date('c') . ": $signal => $ticket $orderAmount\n";
    echo $log;
    file_put_contents(__DIR__ . '/run.log', $log, FILE_APPEND);
    return;

    $result = $bitfinex->order()->postSubmit([
        'type'      => 'MARKET', // todo to limit?!
        'symbol'    => $ticket,
      //'price'     => '1000',
        'amount'    => $orderAmount, //Amount of order (positive for buy, negative for sell)
    ]);
    print_r($result);

}catch (\Exception $e){
    print_r(json_decode($e->getMessage(),true));
}
