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

logger("INPUT> $signal");

if ($config['locking'] ?? true) {
    $lock = fopen(__DIR__ . '/run.log', 'r+');
    flock($lock, LOCK_EX); // wait for lock...
}

[$_ticker, $_dir, $_amount] = explode(',', $signal);
$ticker         = 't'. trim($_ticker);
$dir            = trim($_dir);
$amount         = intval(trim($_amount));

$dry            = $config['dry']           ?? false; // dry run
$multiply       = $config['multiply']      ?? 10; // percent for one signal
$max_positions  = $config['max_positions'] ?? 10;

$what           = substr($ticker, 1, 3);
$base           = substr($ticker, -3, 3);
$price          = $bitfinex->calc()->postTradeAvg(['symbol' => 't' . $what . 'USD', 'amount' => 1,])[0];
$positions      = $bitfinex->position()->post([]);
$_index         = array_search($ticker, array_column($positions, 0));
$position       = $_index ? $positions[$_index] : null; // position with current ticker

if (isset($config['telegram_token'])) {
    if ($price > 10) {
        $formatPrice = number_format($price, 0);
    } elseif ($price > 0.1) {
        $formatPrice = number_format($price, 2);
    } else {
        $formatPrice = number_format($price, 6);
    }
    (new Telegram($config['telegram_token']))->sendMessage([
        'chat_id' => $config['telegram_chat_id'], 'text' => "$dir #$_ticker @ $formatPrice ~ $amount",
    ]);
}

// close position if we have positions of the same ticket
if ($position and ($amount > 1 or $dir === 'trail')) {
    try {
        $closeAmount = -1 * $position[2];
        $closeMargin = $bitfinex->order()->postSubmit([
            'type'   => 'MARKET',
            'symbol' => $ticker,
            'amount' => (string) $closeAmount, //Amount of order (positive for buy, negative for sell)
            'meta'   => ['aff_code' => 'nt8rjwkL7'],
        ]);
        logger("$signal => $ticker => CLOSE $closeAmount " . $closeMargin[0]);
    } catch (\Exception $e) {
        logger($e->getMessage());
    }
}

if (!$position and count($positions) >= $max_positions) {
    logger("$signal => MAX POSITIONS = $max_positions");
} elseif (in_array($dir, ['sell', 'buy'])) {
    try {
        $amount_order = 1 * ($dir === 'sell' ? -1 : 1); // в итоге берем после закрытия максимум на *1

        // Place an Order
        $balance = $bitfinex->position()->postInfoMarginKey(['key'=>'base'])[1][2];

        $orderAmount = round(($balance / $price) * ($multiply / 100) * $amount_order, 8);

        logger("$signal => $ticker $orderAmount");

        if (!$dry) {
            $result = $bitfinex->order()->postSubmit([
                'type'   => 'MARKET',
                'symbol' => $ticker,
                'amount' => (string) $orderAmount, //Amount of order (positive for buy, negative for sell)
                'meta'   => ['aff_code' => 'nt8rjwkL7'],
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
