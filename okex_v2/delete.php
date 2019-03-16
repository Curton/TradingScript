<?php session_start();?>
<meta http-equiv="refresh" content="3">
<?php
require_once (dirname(__FILE__) . '/OKCoin/OKCoin.php');

const API_KEY = "";
const SECRET_KEY = "";
const BILL_LIMIT_HIGH = 66;
const BILL_LIMIT_LOW  = 56;
const DELLETE_BID_BASE = 0.93;
const DELLETE_BID_OFFSET = 0.04;
const ROUND_DIGIT = 3;
const STOP_LOW_LIMIT = 30;

const STOP_TIMESTAMP = 0;
const RESTART_TIMESTAMP = 0;

function getRandPrice($base, $offset) {
	$rand = mt_rand(0, 1000) / 1000;
	return $base + $offset * $rand;
}

function get_last_order_id($client, $type, $page) {
	$params = array('api_key' => API_KEY, 'symbol' => 'eth_usd', 'status' => '1','order_id' => '-1', 'contract_type' => 'quarter', 'current_page' => "$page", 'page_length' => '50');
	// print_r($params);
	$result = $client -> getOrderFutureApi($params);
	// print_r($result);

	$last_order_id = 0;

	foreach ($result->{'orders'} as $key) {
		if ($key->{'type'} == $type) {
			$last_order_id = $key->{'order_id'};
		}
	}
	return $last_order_id;
}

function get_order_info($client, $order_id) {
	// echo "$order_id";
	$params = array('api_key' => API_KEY, 'symbol' => 'eth_usd', 'order_id' => "$order_id", 'contract_type' => 'quarter');
	$result = $client -> getOrderFutureApi($params);
	return $result;
}

function print_result($result, $type) {

	echo "<br>$type : (<br>";
	$symbol = $result->{'orders'};
	$symbol = $symbol[0]->{'symbol'};
	echo "&emsp; [symbol] => $symbol <br>";

	$amount = $result->{'orders'};
	$amount = $amount[0]->{'amount'};
	echo "&emsp; [amount] => $amount <br>";

	$type = $result->{'orders'};
	$type = $type[0]->{'type'};
	echo "&emsp; [type] => $type <br>";

	$price = $result->{'orders'};
	$price = $price[0]->{'price'};
	echo "&emsp; [price] => $price <br>";

	$order_id = $result->{'orders'};
	$order_id = $order_id[0]->{'order_id'};
	echo "&emsp; [order_id] => $order_id <br>";

	echo ")<br>";
	return 0;
}

function close_last_order($client, $order_id, $type, $high, $amount) {
	// cancel order
	$params = array('api_key' => API_KEY, 'symbol' => 'eth_usd', 'order_id' => "$order_id", 'contract_type' => 'quarter');
	$result = $client -> cancelFutureApi($params);
	echo "<br>";
	echo "Close last order : ";
	print_r($result);
	echo "<br>";
	// make order
	$params = array('api_key' => API_KEY, 'symbol' => 'eth_usd', 'contract_type' => 'quarter', 'price' => "$high", 'amount' => "$amount", 'type' => "$type", 'lever_rate' => '10');
	//print_r($params);
	$result = $client -> tradeFutureApi($params);
	print_r($result);
	echo "<br>";

}

function add_extra_order($client, $high, $low, $amount) {
	$params = array('api_key' => API_KEY, 'symbol' => 'eth_usd', 'contract_type' => 'quarter', 'price' => "$high", 'amount' => "$amount", 'type' => '2', 'lever_rate' => '10');
	$result = $client -> tradeFutureApi($params);
	print_r($result);
	// print_r($params);
	echo "<br>";
	sleep(1);
	// this can be optimized
	if($result->{'result'} != 1) {
			//cancel counting
			echo "<br>No real bill<br>";
	}

	$params = array('api_key' => API_KEY, 'symbol' => 'eth_usd', 'contract_type' => 'quarter', 'price' => "$low", 'amount' => "$amount", 'type' => '4', 'lever_rate' => '10');
	//print_r($params);
	$result = $client -> tradeFutureApi($params);
	print_r($result);
	echo "<br>";
}

function getCurrentPrice($client) {
	$params = array('symbol' => 'eth_usd', 'contract_type' => 'quarter');
	$result = $client -> tickerFutureApi($params);
	return $result->{'ticker'}->{'last'};
}
	
// set session
if(!isset($_SESSION['add'])) {
	$_SESSION['add'] = 0;
}

if(!isset($_SESSION['delete'])) {
	$_SESSION['delete'] = 0;
}

try {
	// set time zone
	date_default_timezone_set('Asia/Shanghai');
	// display time
	echo "Time : ".date("h:i:s")."<br>".PHP_EOL;
	$unix_timestamp = mktime();
	echo "Current Unix timestamp :".$unix_timestamp."<br>".PHP_EOL;
	// time to stop
	if(STOP_TIMESTAMP > 0) {
		if($unix_timestamp >= STOP_TIMESTAMP) {
			die("Stop running : Reach running time limitation.");
		}
		echo "Remain time to stop running : ".(STOP_TIMESTAMP - $unix_timestamp)."<br>".PHP_EOL;
	}
	// display exe. info
	echo "Run count (add) : ".$_SESSION['add']."<br>".PHP_EOL;
	echo "Run count (delete) : ".$_SESSION['delete']."<br>".PHP_EOL;
	// start client
	$client = new OKCoin(new OKCoin_ApiKeyAuthentication(API_KEY, SECRET_KEY));
	// get current price
	$current = getCurrentPrice($client);
	echo "current price : ".$current."<br>".PHP_EOL;

	// get user position
	$params = array('api_key' => API_KEY, 'symbol' => 'eth_usd', 'contract_type' => 'quarter');
	$result = $client -> positionFutureApi($params);
	$holding = $result->{'holding'};
	$holding = $holding[0];
	// $buy = $holding->{'buy_amount'};
	$sell = $holding->{'sell_amount'};
	// status
	echo "<br>SELL AMOUNT : ".BILL_LIMIT_LOW." / ".$sell." / ".BILL_LIMIT_HIGH."<br>".PHP_EOL;

	if ($sell < STOP_LOW_LIMIT) {
		die("Reach STOP_LOW_LIMIT<br>".PHP_EOL);
	}
	// $abs = abs($buy - $sell);
	// echo "ABS(BUY, SELL) : ".$abs."<br>";

	// loop
	$i = 1;
	// 3 : UP ; 4 : DOWN
	$type = 4;
	$final_last_order_id = 0;
	while (get_last_order_id($client, $type, $i) > 0) {
		# code...
		$final_last_order_id = get_last_order_id($client, $type, $i);
		$i += 1;
	}

	if ($final_last_order_id == 0) {
		echo "No floor order!<br>";
		// exit(0);
	} else {
		$result = get_order_info($client, $final_last_order_id);
		print_result($result, "Sell");
		$amount = $result->{'orders'};
		$amount = $amount[0]->{'amount'};
		$high = round(1.003 * $current, ROUND_DIGIT);

		if(($sell > BILL_LIMIT_HIGH)) {
			echo "<br> Cancel last order! <br>";
			close_last_order($client, $final_last_order_id, $type, $high, $amount);
			$_SESSION['delete'] += 1;
		}

		// check if add order
		if($sell < BILL_LIMIT_LOW) {
			echo "<br> Add extra order! <br>";
			// $low = floor(0.93 * $current);
			$low = getRandPrice(DELLETE_BID_BASE, DELLETE_BID_OFFSET);
			$low = round($low * $current, ROUND_DIGIT);
			add_extra_order($client, round(0.997 * $current, ROUND_DIGIT), $low, 1);
			$_SESSION['add'] += 1;
		}
	}

} catch (Exception $e) {
	$msg = $e -> getMessage();
	error_log($msg);
}
