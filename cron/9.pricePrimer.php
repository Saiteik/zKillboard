<?php

require_once '../init.php';

global $primePrices;

if ($primePrices != true) exit();

$date = date('Ymd', time() - 7200);
$yesterday = date('Y-m-d', time() - 7200 - 86400);
$key = "tq:pricesChecked:$date";
if ($redis->get($key) == "true") exit();

Status::check('esi');
$guzzler = new Guzzler(10, 10);
$guzzler->call("$esiServer/v1/markets/groups/", "groupsSuccess", "fail");
$guzzler->finish();

$redis->setex($key, 86400, "true");

function groupsSuccess($guzzler, $params, $content)
{
    global $esiServer;

    $groups = json_decode($content, true);
    foreach ($groups as $groupID) {
        $guzzler->call("$esiServer/v1/markets/groups/$groupID/", "groupSuccess", "fail");
    }  
}

function groupSuccess($guzzler, $params, $content)
{
    global $redis, $esiServer;

    $group = json_decode($content, true);
    $marketGroupID = $group['market_group_id'];
    $types = $group['types'];

    $today = date('Ymd', time());
    $key = "tq:pricesFetched:$today";

    foreach ($types as $typeID) {
        $typeInfo = Info::getInfo("typeID", $typeID);
        $name = strtolower($typeInfo['name']);

        if ($typeInfo['published'] != true) continue;
        if ($redis->hget($key, $typeID) == true) continue;

        $guzzler->call("$esiServer/v1/markets/10000002/history/?type_id=$typeID", "typeHistorySuccess", "fail", ['typeID' => $typeID]);
    }
}

function typeHistorySuccess($guzzler, $params, $content)
{
    global $mdb, $redis;

    $type = json_decode($content, true);
    $typeID = (int) $params['typeID'];
    $row = $mdb->findDoc("prices", ['typeID' => $typeID]);
    foreach ($type as $r) {
        $avgPrice = $r['average'];
        $date = $r['date'];
        $row[$date] = $avgPrice;
    }
    $row['typeID'] = $typeID;

    $mdb->save("prices", $row);

    $today = date('Ymd', time());
    $key = "tq:pricesFetched:$today";
    $redis->hSet($key, $typeID, true);
    $redis->expire($key, 86400);
}

function fail($guzzler, $params, $error)
{
    echo "Fail " . $params['uri'] . "\n";
}
