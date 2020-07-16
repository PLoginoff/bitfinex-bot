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
use Telegram\Bot\Api as Telegram;

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
if ($amount > 1 or $dir === 'trail') {
    $positions = $bitfinex->position()->post([]);
    $index     = array_search($ticket, array_column($positions, 0));
    if ($index) {
        $orderInfo   = $positions[$index];
        $closeAmount = -1 * $orderInfo[2];
        $closeMargin = $bitfinex->order()->postSubmit([
            'type'      => 'MARKET',
            'symbol'    => $ticket,
            'amount'    => (string)$closeAmount, //Amount of order (positive for buy, negative for sell)
        ]);
    }
}

if ($dir === 'trail') {
    $date = date('c');
    $log = "\n$date: $signal => $ticket";
    $log .= $closeAmount??' Позиции не обнаружено';
    $log .= $closeMargin[0]??'';
    file_put_contents(__DIR__ . '/run.log', $log, FILE_APPEND);
} else {
    $amount = 1;
    if ($dir === 'sell') {
        $amount = -1 * $amount;
    }
    // Place an Order
    try {
        $price   = $bitfinex->calc()->postTradeAvg(['symbol' => 't' . $what . 'USD', 'amount' => 1,])[0];
        $balance = $bitfinex->position()->postInfoMarginKey(['key'=>'base'])[1][2];

        $orderAmount = round((3.3 * $balance / $price) * ($multiply / 100) * $amount, 8);

        $log = date('c') . ": $signal => $ticket $orderAmount\n";

        echo $log;
        file_put_contents(__DIR__ . '/run.log', $log, FILE_APPEND);

        if (!$dry) {
            $result = $bitfinex->order()->postSubmit([
                'type'      => 'MARKET',
                'symbol'    => $ticket,
                'amount'    => (string) $orderAmount, //Amount of order (positive for buy, negative for sell)
            ]);

            $orderStatus = $result[0];
            echo $orderStatus . "\n";
            file_put_contents(__DIR__ . '/run.log', $result[0] . "\n", FILE_APPEND);
        }
    }catch (\Exception $e){
        file_put_contents(__DIR__ . '/run.log', $e->getMessage() . "\n", FILE_APPEND);
    }
}

if (isset($config['telegram_token'])) {
    $telegram = new Telegram($config['telegram_token']);
    $formatPrice = $price > 1 ? number_format($price, 0) : number_format($price, 2);
    $telegram->sendMessage([
        'chat_id'   => $config['telegram_chat_id'],
        'text'      => "$dir #$ticket_ @ $formatPrice ~ " . $amount_,
    ]);
}
