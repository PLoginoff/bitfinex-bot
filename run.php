<?php

require 'Bitfinex.php';
$config = require 'config.php';

$client = new Bitfinex($config['key'], $config['secret']);

var_dump($client->get_balances());
var_dump($client->submitOrder());
