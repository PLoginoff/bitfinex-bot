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
$signal   = trim($argv[1] ?? file_get_contents('php://input'));

if (!$signal) {
    exit('php run.php "BTCUSD, sell, 1"\n');
}

if (isset($config['next'])) {
    file_get_contents($config['next'], false, stream_context_create(['http' => ['method' => 'POST', 'content' => $signal]]));
}

[$ticket_, $dir_, $amount_] = explode(',', $signal);

$dry            = $config['dry']           ?? false; // dry run
$multiply       = $config['multiply']      ?? 10; // percent for one signal
$max_positions  = $config['max_positions'] ?? 10;

$ticket     = 't'. trim($ticket_);
$dir        = trim($dir_);
$amount     = intval(trim($amount_));
$amount_dir = $amount * ($dir === 'sell' ? -1 : 1);

$what       = substr($ticket, 1, 3);
$base       = substr($ticket, -3, 3);
$price      = $bitfinex->calc()->postTradeAvg(['symbol' => 't' . $what . 'USD', 'amount' => 1,])[0];
$positions  = $bitfinex->position()->post([]);

if (isset($config['telegram_token'])) {
    $telegram = new Telegram($config['telegram_token']);
    $formatPrice = $price > 1 ? number_format($price, 0) : number_format($price, 2);
    $telegram->sendMessage([
        'chat_id'   => $config['telegram_chat_id'],
        'text'      => "$dir #$ticket_ @ $formatPrice ~ " . $amount_,
    ]);
}

if (count($positions) >= $max_positions) {
    logger("$signal => MAX POSITIONS = " . $max_positions);
    exit(1);
}

$_index = array_search($ticket, array_column($positions, 0));
$position = $_index ? $positions[$_index] : null; // position with current ticker

// close position if we have positions of the same ticket
if ($position and ($amount > 1 or $dir === 'trail')) {
    $closeAmount = -1 * $position[2];
    try {
        $closeMargin = $bitfinex->order()->postSubmit([
            'type'      => 'MARKET',
            'symbol'    => $ticket,
            'amount'    => (string)$closeAmount, //Amount of order (positive for buy, negative for sell)
        ]);
        logger("$signal => $ticket => CLOSE $closeAmount " . $closeMargin[0]);
    } catch (\Exception $e) {
        logger($e->getMessage());
    }
}

if ($dir !== 'trail') {
    $amount = 1; // fixme нафига это??
    if ($dir === 'sell') {
        $amount = -1 * $amount;
    }
    // Place an Order
    try {
        $balance = $bitfinex->position()->postInfoMarginKey(['key'=>'base'])[1][2];

        $orderAmount = round((3.3 * $balance / $price) * ($multiply / 100) * $amount, 8);

        logger("$signal => $ticket $orderAmount");

        if (!$dry) {
            $result = $bitfinex->order()->postSubmit([
                'type'      => 'MARKET',
                'symbol'    => $ticket,
                'amount'    => (string) $orderAmount, //Amount of order (positive for buy, negative for sell)
            ]);
            logger('Submit: ' . $result[0]);
        }
    } catch (\Exception $e) {
        logger($e->getMessage());
    }
}

function logger($message)
{
    $log = date('c') . ": $message\n";
    file_put_contents(__DIR__ . '/run.log', $log, FILE_APPEND);
    echo $log;
}
