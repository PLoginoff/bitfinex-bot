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

if (isset($config['next'])) {
    file_get_contents($config['next'], false, stream_context_create(['http' => ['method' => 'POST', 'content' => $signal]]));
}

if (!$signal) {
    exit('php run.php "BTCUSD, sell, 1"\n');
}

[$ticket_, $dir_, $amount_] = explode(',', $signal);

//$signal = explode(' ','BTCUSD 1');

$dry      = $config['dry']      ?? false; // dry run
$multiply = $config['multiply'] ?? 10; // percent for one signal

$ticket = 't'. trim($ticket_);
$dir    = trim($dir_);
$amount = intval(trim($amount_));

$what   = substr($ticket, 1, 3);
$base   = substr($ticket, -3, 3);

// if we have positions of the same ticket
if ($amount > 1) {
    $positions = $bitfinex->position()->post([]);
    $check = in_array($ticket, array_column($positions,0));
    if (!$check) {
        $amount = 1;
    }
}

if ($dir === 'sell') {
    $amount = -1 * $amount;
}

// Place an Order
try {
    $price   = $bitfinex->calc()->postTradeAvg(['symbol' => 't' . $what . 'USD', 'amount' => 1,])[0];
    $balance = $bitfinex->position()->postInfoMarginKey(['key'=>'base'])[1][2];

    $orderAmount = round(($balance / $price) * ($amount / 100) * $multiply, 8);

    $log = date('c') . ": $signal => $ticket $orderAmount\n";

    echo $log;
    file_put_contents(__DIR__ . '/run.log', $log, FILE_APPEND);

    if (!$dry) {
        $result = $bitfinex->order()->postSubmit([
            'type'      => 'MARKET', // todo to limit?!
            'symbol'    => $ticket,
            //'price'     => null,
            'amount'    => (string) $orderAmount, //Amount of order (positive for buy, negative for sell)
        ]);

        $orderStatus = $result[0];
        echo $orderStatus . "\n";
        file_put_contents(__DIR__ . '/run.log', $result[0] . "\n", FILE_APPEND);
    }
}catch (\Exception $e){
    file_put_contents(__DIR__ . '/run.log', $e->getMessage() . "\n", FILE_APPEND);
}
