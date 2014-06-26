<?

function debug_out ($vars) {
	echo "<pre>";
	print_r($vars);
	echo "</pre>";
}

function connect_to_maps ($db_file) {
	try {
		$dbh = new PDO('sqlite:' . $db_file);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	} catch (PDOException $e) {
		echo ($e->getMessage());
		exit();
	}
	return $dbh;
}

function map_exists (&$map_db, $table, $search) {
	
	$query = "SELECT substitute FROM {$table} WHERE search LIKE ?;";
	
	// Prepare SQL, Execute the SQL and catch any exception thrown.
	//
	try {
		$sth = $map_db->prepare($query);
		$sth->execute(array($search));
		$rows = $sth->fetchColumn();
		$sth->closeCursor();
	} catch (PDOException $e) {
		echo $e->getMessage();
		exit();
	}
	
	if (!empty($rows)) {
		return $rows;
	} else {
		return FALSE;
	}
}

function parse_bgp_attr ($flags) {
	$bgp_attr = array();
	
	foreach ($flags as $flag) {
		if (stripos($flag, 'Origin') !== FALSE) {
			$bgp_attr['origin'] = substr($flag, strpos($flag, ' ')+1);
		} elseif (stripos($flag, 'metric') !== FALSE) {
			$bgp_attr['metric'] = substr($flag, strpos($flag, ' ')+1);
		} elseif (stripos($flag, 'localpref') !== FALSE) {
			$bgp_attr['localpref'] = substr($flag, strpos($flag, ' ')+1);
		} elseif (stripos($flag, 'valid') !== FALSE) {
			$bgp_attr['valid'] = TRUE;
		} elseif (stripos($flag, 'internal') !== FALSE) {
			$bgp_attr['internal'] = TRUE;
		}
		
	}
	return $bgp_attr;
}

function parse_bgp_ios (&$lines) {
	
	$bgp_entries = array();
	
	foreach ($lines as $line) {
				
		// Get table entry line.
		//
		if (preg_match ('/BGP routing table entry for ([\d\.\/]+)/', $line, $matches)) {
			$route = $matches[1];
			continue;
		}
				
		if (preg_match ('/^Paths: \((\d+) available, best #(\d+)\)/', $line, $matches)) {
			$best = $matches[2];
			$total_routes = $matches[1];
			continue;
		}

		if (preg_match ('/^\s+Path #(\d+): /', $line, $matches)) {
			$current_path = $matches[1];
			$bgp_entries['paths'][$current_path] = array();
			continue;
		}
		
		// AS Path
		//
		if (isset($current_path) && preg_match ('/^\s+([\d\s]+), \(/', $line, $matches)) {
			$aspath = array_map('trim', explode(' ', $matches[1]));
			$bgp_entries['paths'][$current_path]["aspath"] = $aspath;
			continue;
		}
		
		if (isset($current_path) && preg_match ('/^\s+([\d\s]+)$/', $line, $matches)) {
			$aspath = array_map('trim', explode(' ', $matches[1]));
			$bgp_entries['paths'][$current_path]["aspath"] = $aspath;
			continue;
		}
		
		if (isset($current_path) && preg_match ('/^\s+Local\s*$/', $line)) {
			$bgp_entries['paths'][$current_path]["aspath"] = array('Local');
			continue;
		}
		
		// Communities
		//
		if (isset($current_path) && preg_match ('/^\s+Community: ([\d\s\:]+)$/', $line, $matches)) {
			$communities = array_map('trim', explode(' ', $matches[1]));
			$bgp_entries['paths'][$current_path]["communities"] = $communities;
			continue;
		}
		
		// Next Hop
		//
		if (isset($current_path) && preg_match ('/^\s+(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}).*from \d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3} \(/', $line, $matches)) {
			$nexthop = $matches[1];
			$bgp_entries['paths'][$current_path]["nexthop"] = $nexthop;
			continue;
		}
		
		if (isset($current_path) && preg_match ('/^\s+Origin |localpref |metric/', $line)) {
			$bgp_attr = array_map('trim', explode(', ', $line));
			$bgp_entries['paths'][$current_path]["bgp_attr"] = parse_bgp_attr ($bgp_attr);
		}
	}
	if (isset($best) && isset($route) && isset($total_routes)) {
		$bgp_entries['best'] = $best;
		$bgp_entries['route'] = $route;
		$bgp_entries['total_paths'] = $total_routes;
	}
	return $bgp_entries;
}

function parse_bgp_junos (&$lines) {
			
	$bgp_entries = array();

	$current_path = 0;

	foreach ($lines as $line) {
				
		// Get route
		//
		if (preg_match ('/(.+\/\d+) \((\d+) entries, \d+ announced\)/', $line, $matches)) {
			$route = $matches[1];
			$total_routes = $matches[2];
			continue;
		}
				
		if (preg_match ('/^\s+\*?BGP\s+Preference:/', $line, $matches)) {
			$current_path++;
			$bgp_entries['paths'][$current_path] = array();
			$bgp_entries['paths'][$current_path]["bgp_attr"] = array();
			continue;
		}

		if (preg_match ('/State: .*Active/', $line)) {
			$best = $current_path;
			continue;
		}

		// AS Path
		// 
		if (isset($current_path) && preg_match ('/^\s+AS path:\s+([\d\s]+)/', $line, $matches)) {
			$aspath = array_map('trim', explode(' ', trim($matches[1])));
			$bgp_entries['paths'][$current_path]["aspath"] = $aspath;
			continue;
		}
						
		if (isset($current_path) && preg_match ('/^\s+AS path:\s+[I\?]/', $line)) {
			$bgp_entries['paths'][$current_path]["aspath"] = array('Local');
			continue;
		}
		
		// Communities
		//
		if (isset($current_path) && preg_match ('/^\s+Communities: ([\d\s\:]+)$/', $line, $matches)) {
			$communities = array_map('trim', explode(' ', $matches[1]));
			$bgp_entries['paths'][$current_path]["communities"] = $communities;
			continue;
		}
		
		// Next Hop
		//
		if (isset($current_path) && preg_match ('/^\s+Protocol next hop: (.+)/', $line, $matches)) {
			$nexthop = $matches[1];
			$bgp_entries['paths'][$current_path]["nexthop"] = trim($nexthop);
			continue;
		}
		
		if (isset($current_path) && preg_match ('/^\s+Localpref:\s+(\d+)/', $line, $matches)) {
			$bgp_entries['paths'][$current_path]["bgp_attr"]['localpref'] = $matches[1];
		}
		if (isset($current_path) && preg_match ('/^.+Metric:\s+(\d+)/', $line, $matches)) {
			$bgp_entries['paths'][$current_path]["bgp_attr"]['metric'] = $matches[1];
		}
	}

	if (isset($best) && isset($route) && isset($total_routes)) {
		$bgp_entries['best'] = $best;
		$bgp_entries['route'] = $route;
		$bgp_entries['total_paths'] = $total_routes;
	}
	return $bgp_entries;
}

function parse_bgp (&$lines, $software) {
	
	switch ($software) {
		case "IOS":
		case "IOS-XR":
			$bgp_entries = parse_bgp_ios($lines);
			break;
		case "JunOS":
			$bgp_entries = parse_bgp_junos($lines);
			break;
	}
	
	//debug_out($bgp_entries);
	return $bgp_entries;
}

function format_communities (&$map_db, $communities) {
	
	$comm_string = '';
	$comm_fmt = array();
	$comm_nonfmt = array();

	// Get each community into array, so formatted can go first.
	foreach ($communities as $community) {
		if ($map_db && ($sub = map_exists($map_db, 'COMMAPS', $community)) !== FALSE) {
			$comm_fmt[] = $sub . " [" . $community . "]";
		} else {
			$comm_nonfmt[] = $community;
		}
	}
	
	// Now merge the arrays and format them so 4 communities per line
	// seperated by a comma.
	$i = 0;
	$tc = count ($communities);
	
	foreach (array_merge($comm_fmt, $comm_nonfmt) as $comm) {
		$i++;
		if ($i % 4 == 0) {
			$str = $comm . ",<br />\n&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;"; 
		} elseif ($i != $tc) {
			$str = $comm . ', ';
		} else {
			$str = $comm;
		}
		$comm_string .= $str;
	}
	
	return $comm_string;
}

function format_aspath (&$map_db, $aspath) {
	
	$aspath_string = '';
	$aspath_translated = '';
	
	foreach ($aspath as $asnum) {
		if ($map_db && ($sub = map_exists($map_db, 'ASMAPS', $asnum)) !== FALSE) {
			$aspath_translated .= $sub . " ";
			$aspath_string .= $asnum . " ";
		} else {
			$aspath_string .= $asnum . " ";
			$aspath_translated .= 'AS' . $asnum . " ";
		}
	}
	return array('numeric' => $aspath_string, 'translated' => $aspath_translated);
}

function format_bgp_attr ($bgp_attr) {
	
	$bgp_attr_string = array();
	
	foreach ($bgp_attr as $flag => $value) {
		if ($value !== TRUE) {
			$bgp_attr_string[] = ucfirst($flag) . " " . $value;
		} else {
			$bgp_attr_string[] = $flag;
		}
	}
	return implode(', ', $bgp_attr_string);
}

function bgp_output (&$bgp_entries, $pretty, $maps_db_file) {
	
	if ($pretty) {
		$dbh = connect_to_maps ($maps_db_file);
	} else {
		$dbh = NULL;
	}
	
	$output[] = "BGP routing table entry for " . $bgp_entries['route'] . "<br /><br />\n";
	$output[] = "Paths: (" . $bgp_entries['total_paths'] . " available, best #" . $bgp_entries['best'] . "):<br />\n";
	foreach ($bgp_entries['paths'] as $num => $path) {
		if ($num == $bgp_entries['best']) {
			$output[] = "<span class=\"best_path\">\n";
		}
		
		$bgp_attr = format_bgp_attr($path['bgp_attr']);
		$aspath = format_aspath($dbh, $path['aspath']);
		$communities = format_communities($dbh, $path['communities']);
		
		$output[] = "<br /><span class=\"as_path\">\n";
		$output[] = "&nbsp;" . $aspath['numeric'] . "<br />\n";
		$output[] = "</span>\n";
		
		if ($pretty) {
			$output[] = "&nbsp;&nbsp;&nbsp;" . gethostbyaddr($path['nexthop']) . " (" . $path['nexthop'] . ")<br />\n"; 
		} else {
			$output[] = "&nbsp;&nbsp;&nbsp;" . $path['nexthop'] . "<br />\n";
		}
		
		if ($pretty) {
			$output[] = "&nbsp;&nbsp;&nbsp;&nbsp;AS path translation: <i>" . $aspath['translated'] . "</i><br />\n";
		}
		$output[] = "&nbsp;&nbsp;&nbsp;&nbsp;BGP attributes: " . $bgp_attr . "<br />\n";
		$output[] = "&nbsp;&nbsp;&nbsp;&nbsp;Communities: " . $communities . "<br />\n";
		if ($num == $bgp_entries['best']) {
			$output[] = "</span>\n";
		}
	}
	return $output;
}

function validate_arguments () {

	global $devices;
	global $types;

	// Check the form token matches
	if( !isset($_SESSION['form_token']) || ($_POST['form_token'] !== $_SESSION['form_token'])) {
		return "Invalid form submission: Tokens don't match";
	}
	
	// Check everything is filled in
	//
	if ((!isset($_POST['type']) || empty($_POST['type']))
		|| (!isset($_POST['location']) || empty($_POST['location']))
		|| (!isset($_POST['argument']) || empty($_POST['argument']))) {
		return 'Error in form submission, some values are missing.';
	}
	
	// Check device/location is in devices config array.
	if (!array_key_exists ($_POST['location'], $devices)) {
		return  "Location is not valid.";
	} 

	// Check device/location is in devices config array.
	if (!array_key_exists ($_POST['type'], $types)) {
		return "Command type is not valid.";
	}

	switch ($_POST['type']) {
		
		// show BGP routes
		//
		case "showbgp":
		
			// check IP address or CIDR Bloack
			$a = explode ('/', $_POST['argument']);
			
			// Array will have 2 elements if CIDR Block
			//
			if (count($a) > 1) {
				// Check Valid IP.
				if (filter_var ($a[0], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) {
					// Check Mask is okay.
					if ($a[1] >= 1 && $a[1] <= 32) {
						return TRUE;
					}
				}
			} else {
				if (filter_var ($a[0], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) {
					return TRUE;
				}
			}
			break;
			
		// Ping and Traceroute	
		//
		case "traceroute":
		case "ping":
			if (filter_var ($_POST['argument'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) {
				return TRUE;
			}
			break;
	}
	return "Invalid argument.";
}

function connect_router ($router, $username, $password, $termlen, $quit, $command, $begin, $end) {

	$socket_timeout = 30;
	$header1=chr(0xFF).chr(0xFB).chr(0x1F).chr(0xFF).chr(0xFB).chr(0x20).chr(0xFF).chr(0xFB).chr(0x18).chr(0xFF).chr(0xFB).chr(0x27).chr(0xFF).chr(0xFD).chr(0x01).chr(0xFF).chr(0xFB).chr(0x03).chr(0xFF).chr(0xFD).chr(0x03).chr(0xFF).chr(0xFC).chr(0x23).chr(0xFF).chr(0xFC).chr(0x24).chr(0xFF).chr(0xFA).chr(0x1F).chr(0x00).chr(0x50).chr(0x00).chr(0x18).chr(0xFF).chr(0xF0).chr(0xFF).chr(0xFA).chr(0x20).chr(0x00).chr(0x33).chr(0x38).chr(0x34).chr(0x30).chr(0x30).chr(0x2C).chr(0x33).chr(0x38).chr(0x34).chr(0x30).chr(0x30).chr(0xFF).chr(0xF0).chr(0xFF).chr(0xFA).chr(0x27).chr(0x00).chr(0xFF).chr(0xF0).chr(0xFF).chr(0xFA).chr(0x18).chr(0x00).chr(0x58).chr(0x54).chr(0x45).chr(0x52).chr(0x4D).chr(0xFF).chr(0xF0);
	$header2=chr(0xFF).chr(0xFC).chr(0x01).chr(0xFF).chr(0xFC).chr(0x22).chr(0xFF).chr(0xFE).chr(0x05).chr(0xFF).chr(0xFC).chr(0x21);

	$link = fsockopen ($router, 23, $errno, $errstr, $socket_timeout);

	if (!$link) {
		print ("Error connecting to router");
		return FALSE;;
	}

	stream_set_timeout($link, $socket_timeout);

	// Put some telnet headers, IOS-XR needs header2 again or it disconnects.
	//
	fputs($link, $header1);
	sleep(1);
	fputs($link, $header2);
	sleep(1);
	fputs($link, $header2);
	sleep(1);

	// Send username and password
	//
	fputs ($link, "$username\r\n");
	fputs ($link, "$password\r\n");
	
	// Set terminal Length to Zero
	//
	fputs ($link, "$termlen\r\n");
	fputs ($link, "$command\r\n");
	
	// Exit router
	//
	fputs ($link, "$quit\r\n");

	$output = array();

	//
	// Get the output from the command until the quit command.
	//

	while (!feof ($link) && (strpos (($buf = fgets($link, 1024)), $begin) === FALSE));
	
	$output[] = $buf;
	
	while (!feof ($link) && (strpos (($buf = fgets($link, 1024)), $end) === FALSE)) {
		$output[] = $buf;
	}

	fclose ($link);
	return $output;
}

?>
