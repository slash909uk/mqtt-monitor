<?php
// S Ashby, 16/12/2017, created
// Monitor script to follow MQTT messages from domoticz/out and sonof tele/# topics and alarm if nothing is seen for the configured alarm time
// Waiver IDs can be set to avoid false alarms in $waivers
// supports a control message to topic mqtt-monitor/cmd with optional payloads 'status' 'config' or 'reset' to clear data and responds to mqtt-monitor/status with text output
// tidied debug, leave just one permanent echo to stdut for timeouts
// remove Type="Scene|Group" messages as they are not devices and have overlapping IDX values!
// change waiver system to read from event description for line 'Monitor:true' to include in monitoring setup, else excluded (exclude list is way longer than include list!)

echo "MQTT Monitor started\n";

require "../phpMQTT-master/phpMQTT.php";

$server = "localhost";     // change if necessary
$port = 1883;                     // change if necessary
$username = "";                   // set your username
$password = "";                   // set your password
$client_id = "monitor-subscriber"; // make sure this is unique for connecting to sever - you could use uniqid()
// alarm timeout
$alarm_sec = 720;
$alarm_repeat = 3600;
// waiver list - there are dummyHW outputs (monitored by sonoff telemetry or not required) or RF remote signals that are highly intermettent etc.
//$waivers = array('2845','2602','2601','2596','2595','2496','2490','2388','2365','2345','2344','2343','2133','2132','2131','2124','1716','1715','1714','1713','931','582','584','465','330','309','266','252','251','250','249','248','184','179','167','166','111','110','109','19','10','9');
$waivers = array();
// telemetry interval
$telemetry_sec = 600;
// debug
$debug=false;

// include Syslog class for remote syslog feature
require "../Syslog-master/Syslog.php";
Syslog::$hostname = "localhost";
Syslog::$facility = LOG_DAEMON;
Syslog::$hostToLog = "monitor";

function report($msg, $level = LOG_INFO, $cmp = "mqtt-monitor") {
	global $debug;
	if($debug) echo "mqtt-monitor:".$level.":".$msg."\n";
    Syslog::send($msg, $level, $cmp);
}

$mqtt = new phpMQTT($server, $port, $client_id);

// store last seen times for IDs
$seen = array();
$max = array();
$lasttelemetry = time();
//
// infinite loop here
while (true) {
	if(!$mqtt->connect(true, NULL, $username, $password)) {
		report('MQTT monitor cannot connect to MQTT - retrying in 10 sec',LOG_ERROR);
		sleep(10);
	} else {
		report('MQTT monitor connected to queue:'.$server.':'.$port,LOG_NOTICE);

		$topics['domoticz/out'] = array("qos" => 0, "function" => "procmsg");
		$topics['tele/#'] = array("qos" => 0, "function" => "procmsg");
		$topics['mqtt-monitor/cmd'] = array("qos" => 0, "function" => "procmsg");
		$mqtt->subscribe($topics, 0);

		while($mqtt->proc()){
			$now=time();
			if($lasttelemetry < $now-$telemetry_sec) {
				$tele = 'MQTT monitor telemetry; Ndevices = '.count($seen);
				report($tele,LOG_INFO);
				$lasttelemetry = $now;
			}
		}

		// proc() returned false - reconnect
		report('MQTT monitor lost connection - retrying',LOG_NOTICE);
		$mqtt->close();
	}
}

function procmsg($topic, $msg, $retain){
	global $mqtt;
	global $debug;
	global $seen;
	global $alarm_sec;
	global $alarm_repeat;
	global $waivers;
	global $telemetry_sec;
	global $max;
	$now = time();
	$id=null;
	// skip retain flag msgs (LWT usually)
	if($retain)
		return;
	// process by topic
	if($debug) echo 'msg from:'.$topic."\n";
	if ($topic=='mqtt-monitor/cmd') {
		if($debug) echo "cmd:".$msg."\n";
		if((empty($msg))|| $msg=='status') {
			$data = new stdClass();
			$data->cmd = "status";
			$data->now = $now;
			$data->total = count($seen);
			$data->seen = $seen;
			$data->max = $max;
			$msg = JSON_encode($data);
			$mqtt->publish('mqtt-monitor/status',$msg,0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
		if($msg=='reset') {
			$seen = array();
			$max = array();
			$mqtt->publish('mqtt-monitor/status','{"cmd":"reset!"}',0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
		if($msg=='config') {
			$data = new stdClass();
			$data->cmd = "config";
			$data->alarm_sec = $alarm_sec;
			$data->alarm_repeat = $alarm_repeat;
			$data->telemetry_sec = $telemetry_sec;
			$data->waivers = $waivers;
			$msg = JSON_encode($data);
			$mqtt->publish('mqtt-monitor/status',$msg,0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
	}
	else if ($topic=='domoticz/out') {
		// domoticz msgs - use 'idx' field as ID
		$data = JSON_decode($msg);
		if($debug) echo "domoticz/out:".$msg."\n";
		// Skip if Type == Scene (not a device, overlapping IDX!)
		if(strcmp($data->Type,'Scene')==0)
			return;
		// Skip if Type == Group (not a device, overlapping IDX!)
		if(strcmp($data->Type,'Group')==0)
			return;
		// Skip if description does not contain Monitor:true 
		if(strcmp($data->description,'Monitor:true')!=0)
			return;
		// Skip if name == Unknown (spurious RFXCOM devices)
		if(strcmp($data->name,'Unknown')==0)
			return;
		$id = $data->idx;
		$name = $data->name;
	}
	else {
		// SONOF telemetry msg - use topic 2nd token name as ID - be careful to check there are no duplicate topic names in the network
		$tkn = explode('/',$topic);
		if($debug) { echo "tele:"; print_r($tkn); }
		// prefix ID to avoid clash of possible numeric ID with domo IDX
		$name = $tkn[1];
		$id = 'tele.'.$name;
	}
	// skip if id not found
	if(empty($id))
		return;
	// skip waivers
	if(in_array($id,$waivers))
		return;
	// update last seen time
	if($debug) echo "processing:".$id.":".$name."\n";
	$seen[$id]=array($now,$name);
	if(empty($max[$id])) {
		$max[$id]=0;
	}

	// check for expiry
	if($debug) echo "\n--- ".date('r',$now)."\n";
	foreach($seen as $id => $val) {
		$last = $val[0];
		$name = $val[1];
		$age = $now-$last;
		if($age > $max[$id]) {
			$max[$id] = $age;
		}
		if($age > $alarm_sec) {
			$msg = 'TIMEOUT;'.$id.';'.$name.':'.$age;
			report($msg,LOG_WARN);
			// report TIMEOUT to stdout as well so it gets collected by process manager (init/upstart/systemd/etc)
			echo $msg."\n";
			$seen[$id][0] = $now + $alarm_repeat;
		}
		if($debug) echo "processed:".$id.':'.$name."\t".date('r',$last)."\t".$age.':'.$alarm."\t".$max[$id]."\n";
	}
}
