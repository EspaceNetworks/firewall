<?php

$debug = true;

$thissvc = "firewall";
include 'lock.php';
use \FreePBX\modules\Firewall\Lock;

if (!Lock::canLock($thissvc)) {
	print "Firewall Service already running, not restarting...\n";
	syslog(LOG_WARNING|LOG_LOCAL0, "Firewall Service already running, not restarting...");
	exit;
}

require 'common.php';
fwLog("Starting firewall service");

if (posix_geteuid() !== 0) {
	throw new \Exception("I must be run as root.");
}

// Grab what our database connection settings are
$f = file_get_contents("/etc/freepbx.conf");

preg_match_all("/amp_conf\[['\"](.+)['\"]\]\s?=\s?['\"](.+)['\"];/m", $f, $out);
$mysettings = array();

foreach($out[1] as $id => $val) {
	$mysettings[$val] = $out[2][$id];
}

$fwconf = getSettings($mysettings);

if (!$fwconf['active']) {
	// Don't need to log this
	// print "Not active. Shutting down\n";
	shutdown();
} else {
	print "Starting firewall.\n";
}

// Make sure our conntrack kernel module is configured correctly
include 'modprobe.php';
$m = new \FreePBX\Firewall\Modprobe;
$m->checkModules();
unset($m);

$v = new \FreePBX\modules\Firewall\Validator($sig);
$path = $v->checkFile("Services.class.php");
include $path;
$services = new \FreePBX\modules\Firewall\Services;

// Now, start by grabbing our interfaces, and making sure
// they are configured correctly.
$path = $v->checkFile("Network.class.php");
include $path;
$nets = new \FreePBX\modules\Firewall\Network;

$known = $nets->discoverInterfaces();
foreach ($known as $int => $conf) {
	if (!isset($conf['config']['ZONE']) || !isValidZone($conf['config']['ZONE'])) {
		$nets->updateInterfaceZone($int, "trusted");
		$zone = "trusted";
	} else {
		$zone = $conf['config']['ZONE'];
	}
	$driver->changeInterfaceZone($int, $zone);
}

// Same for our known networks
$nets = array();
if (!empty($fwconf['networkmaps'])) {
	$nets = @json_decode($fwconf['networkmaps'], true);
}
if ($nets && is_array($nets)) {
	foreach ($nets as $n => $zone) {
		list($network, $cidr) = explode("/", $n);
		$driver->addNetworkToZone($zone, $network, $cidr);
	}
}

// Turns out that this is unreliable. Which is why we use sigSleep below.
pcntl_signal(SIGHUP, "sigHupHandler");

// Always run the update the first time.
$lastfin = 1;

while(true) {
	$fwconf = getSettings($mysettings);
	if (!$fwconf['active']) {
		fwLog("Not active. Shutting down");
		shutdown();
	}
	checkPhar();
	$runafter = $lastfin + $fwconf['period'];
	if ($runafter < time()) {
		// We finished more than $period ago. We can run again.
		updateFirewallRules();
		$lastfin = time();
		continue;
	} else {
		// Sleep until we're ready to go again.
		sigSleep($fwconf['period']/10);
	}
}

function getSettings($mysettings) {
	$pdo = getDbHandle($mysettings);
	$sth = $pdo->prepare('SELECT * FROM `kvstore` where `module`=? and id="noid"');
	$sth->execute(array('FreePBX\modules\Firewall'));
	$retarr = array();
	$res = $sth->fetchAll();
	foreach ($res as $row) {
		$retarr[$row['key']] = $row['val'];
	}

	// Should we be running?
	if (isset($retarr['status']) && $retarr['status']) {
		$retarr['active'] = true;
	} else {
		$retarr['active'] = false;
	}

	if (!isset($retarr['refresh'])) {
		$retarr['refresh'] = "normal";
	}

	if ($retarr['refresh'] == "fast") {
		$period = 15;
	} elseif ($retarr['refresh'] == "slow") {
		$period = 120;
	} else {
		$period = 30;
	}
	$retarr['period'] = $period;

	return $retarr;
}

function shutdown() {
	global $thissvc;

	Lock::unLock($thissvc);
	exit;
}

function getDbHandle($mysettings) {
	static $pdo = false;
	// Make sure it hasn't gone away if it previously existed
	if (is_object($pdo)) {
		try {
			$pdo->query("SHOW STATUS;")->execute();
		} catch(\PDOException $e) {
			if ($e->getCode() != 'HY000' || !stristr($e->getMessage(), 'server has gone away')) {
				throw $e;
			} else {
				// Reconnect!
				$pdo = false;
			}
		}
	}

	// Now, do we need to connect or reconnect?
	if (!$pdo) {
		if(empty($mysettings['AMPDBSOCK'])) {
			if (empty($mysettings['AMPDBHOST'])) {
				$conn = "host=localhost";
			} else {
				$conn = "host=".$mysettings['AMPDBHOST'];
			}
		} else {
			$conn = "unix_socket=".$mysettings['AMPDBSOCK'];
		}
		$dsn = $mysettings['AMPDBENGINE'].":$conn;dbname=".$mysettings['AMPDBNAME'].";charset=utf8";
		$pdo = new \PDO($dsn, $mysettings['AMPDBUSER'], $mysettings['AMPDBPASS'], array(\PDO::ATTR_PERSISTENT => true));
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
	}
	return $pdo;
}

function checkPhar() {
	global $thissvc, $v;

	// Check to see if we should restart
	if (pharChanged()) {
		// Something changed.
		fwLog("Change detected.\n");

		// Generic boilerplate security code.
		$g = new \Sysadmin\GPG();
		$dir = dirname(\Phar::running(false));
		if (!$dir) {
			// This should never be run outside of a phar, but, who knows...
			$dir == __DIR__;
		}
		$sigfile = $dir."/../module.sig";
		$sig = $g->checkSig($sigfile);
		if (!isset($sig['config']['hash']) || $sig['config']['hash'] !== "sha256") {
			fwLog("Invalid sig file.. Hash is not sha256 - check $sigfile");
			// We don't use SLEEP as PHP is easily confused.
			sigSleep(10);
			continue;
		}

		$v->updateSig($sig);
		try {
			$v->checkFile("hooks/firewall");
			fwLog("Valid update! Restarting...");
			Lock::unLock($thissvc);
			// Wait 1/2 a second to give incron a chance to catch up
			usleep(500000);
			// Restart me.
			fclose(fopen("/var/spool/asterisk/incron/firewall.firewall", "a"));
			exit;
		} catch(\Exception $e) {
			fwLog("Firewall tampered.  Not restarting! ".$e->getMessage());
		}
	}
}

function updateFirewallRules() {
	// Signature validation and firewall driver
	global $v, $driver, $services;

	// Asterisk user
	$astuser = "asterisk";

	fwLog("Starting update");

	// We want to switch to the asterisk user and ask for the port mappings.
	try {
		if (!$v->checkFile("bin/getservices")) {
			// That should ALREADY throw...
			throw new \Exception("Failed");
		}
	} catch (\Exception $e) {
		fwLog("Can't validate bin/getservices");
		return false;
	}

	exec("su -c /var/www/html/admin/modules/firewall/bin/getservices $astuser", $out, $ret);
	$getservices = @json_decode($out[0], true);
	if (!is_array($getservices) || !isset($getservices['smartports'])) {
		fwLog("Unparseable output from getservices - ".$out[0]." - returned $ret");
		return;
	}

	// Root-only updates:
	//   SSH is only readable by root
	$ssh = $services->getService("ssh");
	if ($ssh['guess'] == true) {
		throw new \Exception("Root user unable to retrieve sshd port! This is a bug!");
	}
	$getservices['services']['ssh'] = $ssh;

	$zones = array("reject" => "reject", "external" => "external", "other" => "other",
		"internal" => "internal", "trusted" => "trusted");

	// This is the list of services we should have.
	$validservices = array();
	foreach ($getservices['services'] as $s => $settings) {

		// Keep this service for later
		$validservices[$s] = $s;

		// Make sure the service is configured correctly
		if (isset($settings['fw'])) {
			$driver->updateService($s, $settings['fw']);
		} else {
			$driver->updateService($s, false);
		}

		// Assign the service to the required zones
		$myzones = array("addto" => array(), "removefrom" => $zones);
		if (!empty($settings['zones']) && is_array($settings['zones'])) {
			foreach ($settings['zones'] as $z) {
				unset($myzones['removefrom'][$z]);
				$myzones['addto'][$z] = $z;
			}
		}
		$driver->updateServiceZones($s, $myzones);
	}

	// Update RTP rules
	$rtp = $getservices['smartports']['rtp'];
	// UDPTL is T38.
	$udptl = $getservices['smartports']['udptl'];
	$driver->setRtpPorts($rtp, $udptl);

	// Update our knownhosts targets
	$driver->updateTargets($getservices);

	// And permit our registrations through
	$driver->updateRegistrations($getservices['smartports']['registrations']);

	// Update blacklist
	$driver->updateBlacklist($getservices['blacklist']);

	// Update our custom ports
	$custrules = $getservices['custom'];
	foreach ($custrules as $id => $rule) {

		// Keep this service for later
		$validservices[$id] = $id;

		$c = $rule['custfw'];

		// If it has a comma, it's multiple ports.
		$requestedports = explode(",", $c['port']);

		// Create our '$ports' array for the driver.
		$ports = array();
		if ($c['protocol'] == "both" || $c['protocol'] == "tcp") {
			foreach ($requestedports as $p) {
				if ($p > 65535 || $p < 0) {
					continue;
				}
				$ports[] = array("protocol" => "tcp", "port" => $p);
			}
		}
		if ($rule['custfw']['protocol'] == "both" || $rule['custfw']['protocol'] == "udp") {
			foreach ($requestedports as $p) {
				if ($p > 65535 || $p < 0) {
					continue;
				}
				$ports[] = array("protocol" => "udp", "port" => $p);
			}
		}
		$driver->updateService($id, $ports);
		// Assign the service to the required zones
		$myzones = array("addto" => array(), "removefrom" => $zones);
		foreach ($rule['zones'] as $z) {
			unset($myzones['removefrom'][$z]);
			$myzones['addto'][$z] = $z;
		}
		$driver->updateServiceZones($id, $myzones);
	}

	// Now, purge any services that no longer exist
	$active = $driver->getActiveServices();
	foreach ($active as $as) {
		if (!isset($validservices[$as])) {
			// This should be removed
			$driver->removeService($as);
		}
	}

	fwLog("Update complete.");
}

function sigSleep($secs = 10) {
	// Uses pcntl_sigtimedwait instead of sleep, so we can be sure we catch sighup
	// signals from the OS. This may seem counterproductive, as sleep(3) will wake
	// on *any* signal, but php does all sorts of crazy things to make this
	// unreliable.
	if ($secs < 5) {
		$secs = 5;
	}
	pcntl_sigtimedwait(array(SIGHUP), $sig, $secs);
	if ($sig['signo'] === SIGHUP) {
		sigHupHandler(1);
	}
}

function sigHupHandler($signo) {
	// Sigh.
	checkPhar();
	updateFirewallRules();
}
