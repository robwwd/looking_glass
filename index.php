<?php

include('includes/config.inc.php');
include('includes/functions.inc.php');

session_name($config['session_name']);
session_start();

$output = array();
$type = $config['default_type'];

// The form has been submitted if this is true
//
if (isset($_POST['form_token'])) {

	$device = $_POST['location'];
	$os = $devices[$device]['os'];
	$type = $_POST['type'];
	$argument = $_POST['argument'];
	
	if (($output[] = validate_arguments()) === TRUE) {
	
		// Replace ARG and SOURCE in commands.
		//
		$srch = array ('ARG', 'SOURCE');
		$repl = array ($argument, $devices[$device]['source']);

		$command = str_replace($srch, $repl, $commands[$os][$type]['cmd']);

		$out = connect_router (	$device, $devices[$device]['username'], $devices[$device]['password'], 
									$commands[$os]['termlen'], $commands[$os]['quit'], $command, 
									$commands[$os][$type]['start_text'], $commands[$os][$type]['end_text']
									);
																		
		if ($type == 'showbgp') {
			$output = bgp_output(parse_bgp ($out, $os), $config['pretty_output'], $config['maps_db']);
		} else {
			$output = array_map('nl2br', $out);
		}
	}
}

// Set a form Token
$form_token = md5( uniqid('lg_form_token', true) );

// Add the form token to the current session
$_SESSION['form_token'] = $form_token;


// Display the Page
//
echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.01//EN\" \"http://www.w3.org/TR/html4/strict.dtd\">\n"; 
echo "<html>\n"; 
echo "<head>\n"; 
echo "	<title> " . $config['title'] . " : " . $config['subtitle'] . "</title>\n"; 
echo "	<link rel=\"stylesheet\" href=\"http://yui.yahooapis.com/pure/0.3.0/pure-min.css\">\n"; 
echo "	<link rel=\"stylesheet\" type=\"text/css\" href=\"css/style.css\">\n"; 
echo "</head>\n"; 
echo "<body>\n"; 
echo "	<div id=\"container\">\n"; 
echo "		\n"; 
echo "		<div id=\"header\">\n"; 
echo "			<div class=\"pure-g\">\n"; 
echo "					<div class=\"pure-u-1-3\"><img src=\"" . $config['logo'] . "\"></div>\n"; 
echo "					<div class=\"pure-u-2-3\"><h3>" . $config['title'] . " : " . $config['subtitle'] . "</h3></div>\n"; 
echo "			</div>\n"; 
echo "		</div>\n"; 
echo "		\n"; 
echo "		<div id=\"content\">			\n"; 
echo "			<form class=\"pure-form pure-form-stacked\" action=\"index.php\" method=\"post\" id=\"lg\">\n"; 
echo "				<fieldset>\n"; 
echo "					<legend>Looking Glass</legend>\n"; 
echo "\n"; 
echo "					<div class=\"pure-g\">\n"; 
echo "						<div class=\"pure-u-1-3\">\n"; 


foreach ($types as $k => $t) {
	echo "							<label for=\"" . $k . "\" class=\"pure-radio\">\n";
	echo "								<input id=\"" . $k . "\" type=\"radio\" name=\"type\" value=\"" . $k . "\"";
	if (isset($type) && !empty($type) && $type == $k) {
		echo " checked > \n"; 
	} else {
		echo ">\n";
	}
	echo "								" . $t . "\n"; 
	echo "							</label>\n"; 
}

echo "						</div>\n"; 
echo "\n"; 
echo "						<div class=\"pure-u-1-3\">\n"; 
echo "							<label for=\"location\">Location</label>\n"; 
echo "							<select name=\"location\" id=\"location\" class=\"pure-input-1-2\">\n";

foreach ($devices as $hostname => $location) {
	
	echo "								<option value=\"" . $hostname . "\"";
	if (isset($device) && !empty($device) && $hostname == $device) {
		echo " selected ";
	}
	echo ">" . $location['title'] ."</option>\n"; 
}

echo "							</select>\n"; 
echo "						</div>\n"; 
echo "						\n"; 
echo "						<div class=\"pure-u-1-3\">\n"; 
echo "							<label for=\"argument\">Argument</label>\n"; 
echo "							<input name=\"argument\" id=\"argument\" type=\"text\"";
	
if (isset($argument) && !empty($argument)) {
	echo " value = \"" . $argument . "\">\n"; 
} else {
	echo ">\n";
}

echo "						</div>\n"; 
echo "					</div>\n"; 
echo "					<div class=\"rightalign\">\n"; 
echo "						<button type=\"submit\" class=\"pure-button pure-button-primary\">Submit</button>\n"; 
echo "					</div>\n"; 
echo "                  <input type=\"hidden\" name=\"form_token\" value=\"" . $form_token . "\" />";
echo "				</fieldset>\n"; 
echo "			</form>\n"; 
echo "		</div>\n"; 
echo "		<div id=\"output\">";
foreach ($output as $line) {
	echo "      " . $line;
}
echo "      </div>";
echo "		\n"; 
echo "		<div id=\"footer\">\n"; 
echo "				&copy; Colt - Advanced Technical Services IP\n"; 
echo "		</div>\n"; 
echo "		\n"; 
echo "	</div>	\n"; 
echo "\n"; 
echo "</body>\n"; 
echo "</html>\n"; 
echo "\n";


?> 
