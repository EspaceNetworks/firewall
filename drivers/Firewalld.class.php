<?php
// TODO: Split this into an interface.
namespace FreePBX\modules\Firewall\Drivers;

// Firewalld - RHEL7-ish.
class Firewalld {

	public function getKnownZones() {
		// Caching
		static $out = false;
		if (!$out) {
			// This takes a surprisingly long time.
			exec("/usr/bin/firewall-cmd --list-all-zones", $out, $ret);
		}
		if ($ret) {
			throw new \Exception("Error: $ret - ".json_encode($out));
		}

		$zones = array();

		// Run through the list...
		$currentzone = false;
		foreach ($out as $line) {
			if ($line[0] !== " ") {
				// It's a definition
				$def = explode(" ", $line);
				$currentzone = $def[0];
				if (isset($def[1])) {
					if (strpos($def[1], "default") !== false) {
						$zones[$currentzone]['default'] = true;
					} else {
						$zones[$currentzone]['default'] = false;
					}
					if (strpos($def[1], "active") !== false) {
						$zones[$currentzone]['active'] = true;
					} else {
						$zones[$currentzone]['active'] = false;
					}
				}
				continue;
			}

			// It's a setting!
			if (!$currentzone) {
				throw new \Exception("Somehow got a setting before a zone! ".json_encode($out));
			}

			$settings = explode(":", trim($line));

			if (!isset($settings[1])) {
				$settings[1] = "";
			}
			$zones[$currentzone][$settings[0]] = $settings[1];
		}

		return $zones;
	}

	public function getKnownNetworks() {
		// Returns array that looks like ("network/cdr" => "zone", "network/cdr" => "zone")
		$known = $this->getKnownZones();
		$retarr = array();
		foreach ($known as $z => $settings) {
			if (empty($settings['sources'])) {
				continue;
			}
			$sources = explode(" ", $settings['sources']);
			foreach ($sources as $source) {
				if (!empty($source)) {
					$retarr[$source] = $z;
				}
			}
		}
		return $retarr;
	}

	// Root process
	public function addNetworkToZone($zone = false, $network = false, $cidr = false) {
		$z = new \FreePBX\modules\Firewall\Zones();
		$knownzones = $z->getZones();
		if (!isset($knownzones[$zone])) {
			throw new \Exception("Unknone zone $zone");
		}
		$cmd = "firewall-cmd --permanent --zone=$zone --add-source $network/$cidr";
		exec($cmd, $out, $ret);
		if ($ret) {
			throw new \Exception("Error: $ret - ".json_encode($out));
		}
		return true;
	}

	// Root process
	public function commit() {
		$cmd = "firewall-cmd --reload";
		exec($cmd, $out, $ret);
		if ($ret) {
			throw new \Exception("Error: $ret - ".json_encode($out));
		}
		return true;
	}
}

