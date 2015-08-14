<?php
// vim: :set filetype=php tabstop=4 shiftwidth=4 autoindent smartindent:
namespace FreePBX\modules\Firewall;

class Smart {

	private $db; // DB handle
	private $chansip = false; // This machine uses chansip
	private $pjsip = false; // This machine uses pjsip
	private $iax = false; // This machine uses IAX

	public function __construct($db = false) {

		if (!$db || !($db instanceof \PDO)) {
			throw new \Exception("Need a PDO Database handle");
		}

		$this->db = $db;
		$driver = \FreePBX::Config()->get('ASTSIPDRIVER');
		switch ($driver) {
		case 'both':
			$this->chansip = true;
			$this->pjsip = true;
			break;
		case 'chan_pjsip':
			$this->pjsip = true;
			break;
		case 'chan_sip':
			$this->pjsip = true;
			break;
		default:
			throw new \Exception("Crazy driver setting $driver");
		}

		$this->iax = $this->usesIax();
	}

	private function usesIax() {
		// Does this machine have any IAX devices?
		$sql = "SELECT `id` FROM `iax` LIMIT 1";
		$q = $this->db->query($sql);
		$rows = $q->fetchAll(\PDO::FETCH_ASSOC);
		return (!empty($rows));
	}

	public function getAllPorts() {
		// Returns ALL ports.
		$retarr = array(
			'signaling' => $this->getVoipPorts(),
			'rtp' => $this->getRtpPorts(),
			'known' => $this->getKnown(),
		);
		return $retarr;
	}

	public function getVoipPorts() {
		$ports = $this->getSipPorts();
		if ($this->iax) {
			$ports['udp'][] = $this->getIaxPort();
		}
		return $ports;
	}

	public function getRTPPorts() {
		// These are always open to the world, on every interface.
		// The only limitation is that we don't let people be dumb, and we'll
		// never return less than 1024, or more than 30000. As RTP runs on
		// UTP, it's not THAT critical, but better to be safe than sorry.
		//
		// Yes. This are just random ranges that I made up as 'reasonable'.
		//
		$s = \FreePBX::Sipsettings();
		$sipsettings = $s->genConfig();
		$ports = $sipsettings['rtp_additional.conf']['general'];
		$start = (int) $ports['rtpstart'];
		$end = (int) $ports['rtpend'];
		if ($start < 1024) {
			$start = 1024;
		}
		if ($end > 30000) {
			$end = 30000;
		}

		// Make sure start and end are the right way round...
		if ($end < $start) {
			return array("start" => $end, "end" => $start);
		} else {
			return array("start" => $start, "end" => $end);
		}
	}

	public function getIaxPort() {
		$sql = "SELECT `keyword`, `data` FROM `iaxsettings` WHERE `keyword` LIKE 'bind%'";
		$q = $this->db->query($sql);
		$iax = $q->fetchAll(\PDO::FETCH_ASSOC);

		$bindport = 4569;
		$bindaddr = "0.0.0.0";

		foreach ($iax as $res) {
			if (empty($res['data'])) {
				continue;
			}
			if ($res['keyword'] == "bindport") {
				$bindport = $res['data'];
			} elseif ($res['keyword'] == "bindaddr") {
				$bindaddr = $res['data'];
			}
		}
		return array("dest" => $bindaddr, "dport" => $bindport);
	}

	public function getSipPorts() {
		// Returns an array of ports or ranges used by SIP or PJSIP.
		$udp = array();
		$tcp = array();

		// Let's get chansip settings if we need them.
		if ($this->chansip) {
			$bindport = "";
			$settings = \FreePBX::Sipsettings()->getChanSipSettings(true);
			foreach ($settings as $arr) {
				if (empty($arr['data'])) {
					continue;
				}
				if ($arr['keyword'] == 'bindport') {
					$bindport = $arr['data'];
					break;
				}
			}
			if (empty($bindport)) {
				$bindport = 5060;
			}

			$udp[] = array("dest" => "0.0.0.0", "dport" => $bindport);

			// TODO: chan_sip TCP.. Maybe
		}

		// Do we have pjsip?
		if ($this->pjsip) {
			// Woo. What are our settings?
			$ss = \FreePBX::Sipsettings();
			$allBinds = $ss->getConfig("binds");
			foreach ($allBinds as $type => $listenArr) {
				// What interface(s) are we listening on?
				foreach ($listenArr as $ipaddr => $mode) {
					if ($mode != "on") {
						continue;
					}
					$port = $ss->getConfig($type."port-".$ipaddr);
					if (!$port) {
						continue;
					}
					if ($type == "tcp" || $type == "ws") {
						$tcp[] = array("dest" => $ipaddr, "dport" => $port);
					} elseif ($type == "udp") {
						$udp[] = array("dest" => $ipaddr, "dport" => $port);
					} else {
						throw new \Exception("Unknown protocol $type");
					}
				}
			}
		}

		$retarr = array("udp" => $udp, "tcp" => $tcp);
		return $retarr;
	}

	public function getKnown() {
		// Figure out who our known entities are.
		$discovered = array();

		if ($this->chansip) {
			// Trunks and extens are both in the 'sip' table.
			$sql = "SELECT `data` FROM `sip` WHERE `keyword`='host'";
			$q = $this->db->query($sql);
			$siphosts = $q->fetchAll(\PDO::FETCH_ASSOC);
			foreach ($siphosts as $sip) {
				if ($sip['data'] == "dynamic") {
					continue;
				}
				$discovered[$sip['data']] = true;
			}

			// Does an extension specifically permit a range?
			$sql = "SELECT `data` FROM `sip` WHERE `keyword`='permit'";
			$q = $this->db->query($sql);
			$sippermits = $q->fetchAll(\PDO::FETCH_ASSOC);
			foreach ($sippermits as $sip) {
				$discovered[$sip['data']] = true;
			}
		}

		if ($this->iax) {
			// Find IAX trunks...
			$sql = "SELECT `data` FROM `iax` WHERE `keyword`='host'";
			$q = $this->db->query($sql);
			$iaxhosts = $q->fetchAll(\PDO::FETCH_ASSOC);
			foreach ($iaxhosts as $iax) {
				if ($iax['data'] == "dynamic") {
					continue;
				}
				$discovered[$iax['data']] = true;
			}

			// Extensions?
			$sql = "SELECT `data` FROM `iax` WHERE `keyword`='permit'";
			$q = $this->db->query($sql);
			$iaxpermits = $q->fetchAll(\PDO::FETCH_ASSOC);
			foreach ($iaxpermits as $iax) {
				$discovered[$iax['data']] = true;
			}
		}

		// PJSIP?
		if ($this->pjsip) {
			$sql = "SELECT `data` FROM `pjsip` WHERE `keyword`='sip_server'";
			$q = $this->db->query($sql);
			$pjsiptrunks = $q->fetchAll(\PDO::FETCH_ASSOC);
			foreach ($pjsiptrunks as $p) {
				$discovered[$p['data']] = true;
			}
			// PJSip extensions don't have allow/deny at the moment.
		}

		// Now validate them all!
		$retarr = array();
		foreach (array_keys($discovered) as $d) {
			// Ensure we don't start with "0.0.0.0"
			if (strpos($d, "0.0.0.0") === 0) {
				continue;
			}

			// Is this an IP address?
			if (filter_var($d, FILTER_VALIDATE_IP)) {
				$retarr[] = $d;
				continue;
			}

			// Is this a Network definition?
			if (strpos($d, "/") !== false) {
				// Yes it is.
				$retarr = array_merge($retarr, $this->parseCidr($d));
				continue;
			}

			// Well that means it's a hostname.
			$retarr = array_merge($retarr, $this->lookup($d));
		}
		return $retarr;
	}
	
	public function parseCidr($entry = false) {
		if (!$entry) {
			throw new \Exception("No CIDR Given");
		}

		// To start with, does it have a / in it?
		if (strpos($entry, "/") === false) {
			throw new \Exception("Asked to parse $entry, don't know why");
		}

		// Good.
		list($subnet, $cidr) = explode("/", $entry);

		// And it's a valid subnet, right?
		if (!filter_var($subnet, FILTER_VALIDATE_IP)) {
			// Wut.
			return array();
		}

		// OK. We either have IP/CIDR or a IP/NETMASK
		// If cidr validates as an IP address, that means it's a netmask.
		if (filter_var($cidr, FILTER_VALIDATE_IP)) {
			// netmask.
			return array($subnet."/".$cidr);
		}

		// Otherwise it should be a valid CIDR.
		if ((int) $cidr >= 8 && (int) $cidr <= 32) {
			$netmask = pow(2,$cidr) - 1;
			$netmask = long2ip($netmask << (32 - $cidr));
			return array($subnet."/".$netmask);
		}
		return array();
	}

	public function lookup($host = false) {
		static $cache;

		if (!$host) {
			throw new \Exception("No host given");
		}

		// This is for PHP 5.4 and below
		if (!is_array($cache)) {
			$cache = array();
		}

		// Have we looked this up previously?
		if (!isset($cache[$host])) {
			// No.  OK, so is this an IP?
			if (filter_var($host, FILTER_VALIDATE_IP)) {
				// Well that was easy.
				$cache[$host] = array($host);
				return array($host);
			}

			// Let's do some DNS-ing
			// TODO: See how this goes. It might be better to use something like http://www.purplepixie.org/phpdns/
			//
			$dns = dns_get_record($host);
			$retarr = array();

			// TODO: IPv6
			foreach ($dns as $record) {
				if ($record['type'] == "A") {
					$retarr[$record['ip']] = true;
				}
			}

			$keys = array_keys($retarr);
			$cache[$host] = $keys;
			return $keys;
		}
	}
}
