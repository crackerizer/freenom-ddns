<?php
/*
Freenom Dynamic DNS Updater
Copyright (C) 2016  Phinitnan Chanasabaeng

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
/*

	/***************************************************************************
	 *                            Configuration                                *
	 **************************************************************************/
	$USERNAME = '';
	$PASSWORD = '';
	$DEFAULT_TTL = 600;
	$DEFAULT_HOST = "@";
	$VERBOSE = true;
	$LOG = true;
	$DEBUG = true;

	$DOMAINS = array(
		/* simple */
		array(
			"domain" => "",
		),
		/* optional */
		array(
			"host" => "@,www",
			"domain" => "",
			"ttl" => 300,
		),
	);

	/***************************************************************************
	 *                            Main Program                                 *
	 **************************************************************************/
	/* open log */
	openlog("freenom-ddns", LOG_ODELAY, LOG_CRON);

	/* get public IP */
	$cmd = 'curl -s "https://api.ipify.org/"';

	msg("Getting public IP...");
	dmsg($cmd);

	$IP = exec($cmd);

	if(filter_var($IP, FILTER_VALIDATE_IP) === false) {
		msg('Can not get current public IP address.', true);
		die();
	}

	msg("Public IP: ".$IP);

	/* create temp cookie file */
	$COOKIE = exec('mktemp');
	msg("Cookie: ".$COOKIE);

	/* login */
	$cmd = 	'curl --compressed -k -L -c "'.$COOKIE.
			'" -F "username='.$USERNAME.
			'" -F "password='.$PASSWORD.
			'" "https://my.freenom.com/dologin.php" 2>&1';

	msg('Logging in to Freenom...');
	dmsg($cmd);

	exec($cmd, $result);

	$result = implode("\n", $result);
	
	if(!(strpos($result, 'incorrect%3Dtrue') === false)) {
		msg('Login to Freenom failed.', true);
		die();
	}

	msg('Logged in to Freenom');
	
	/* retrieve domain id */
    $cmd = 	'curl --compressed -k -L -b "'.$COOKIE.
			'" "https://my.freenom.com/clientarea.php?action=domains'.'" 2>&1';

	msg('Getting domain list...');
	dmsg($cmd);	
	
	exec($cmd, $result);
	
	$result = implode("", $result);

    $pattern = '|<td class="second"><a href="(.*)" target="_blank">(.*)'.
                ' <i style="font-size: 12px; color: #CCC;" '.
                'class="fa fa-external-link"></i></a></td>(.*)<a '.
                'class="smallBtn whiteBtn pullRight" '.
                'href="clientarea.php\?action=domaindetails\&id=(.*)">|U';
                
    preg_match_all($pattern, $result, $domain_list);
    
    if($domain_list[2][0] == '') {
        msg('Get domain list failed.', true);
        die();
    }
    
    $user_domains = array();
    
    for($n = 0; $n < count($domain_list[0]); $n++) {
        $user_domains[$domain_list[2][$n]] = $domain_list[4][$n];
    }
	
	dmsg($user_domains);
	
	/* process each domain */
	foreach($DOMAINS as $domain) {
		/* prepare variable */
		if(!(isset($domain['host']) && $domain['host'] != '')) {
			$domain['host'] = $DEFAULT_HOST;
		} else {
			$domain['host'] = $domain['host'];
		}

		$domain['host'] = str_replace(' ', '', $domain['host']);

		$hosts = explode(",", $domain['host']);

		if(isset($domain['ttl']) && is_numeric($domain['ttl'])) {
			$ttl = $domain['ttl'];
		} else {
			$ttl = $DEFAULT_TTL;
		}
		
		$dm = $domain['domain'];

		/* retrieve record html */
		$cmd = 	'curl --compressed -k -L -b "'.$COOKIE.
				'" "https://my.freenom.com/clientarea.php?managedns='.
				$dm.'&domainid='.$user_domains[$dm].'" 2>&1';

		msg('Getting record list: '.$dm);
		dmsg($cmd);

		exec($cmd, $result);

		$result = implode('', $result);

		/* extract records list */
		$pattern = '|<td valign="top">'.
                    '<input type="hidden" name="records\[(.*)\]\[line\]" '.
				    'value="" />'.
                    '<input type="hidden" name="records\[(.*)\]\[type\]" '.
                    'value="(.*)" />'.
                    '<input type="text" name="records\[(.*)\]\[name\]" '.
                    'value="(.*)" size="25" /></td>|U';

		preg_match_all($pattern, $result, $raw_record);

		/* extract ttl list */
		$pattern = 	'|<td valign="top">'.
                    '<input type="text" name="records\[(.*)\]\[ttl\]" '.
                    'value="(.*)" style="width: 60px" /></td>|U';

		preg_match_all($pattern, $result, $raw_ttl);

		/* extract value list */
		$pattern = 	'|<input type="text" name="records\[(.*)\]\[value\]" '.
                    'value="(.*)" size="30" />|U';

		preg_match_all($pattern, $result, $raw_val);

		$record_count = count($raw_record[0]);
		msg("Records in {$dm}: $record_count");

		dmsg($raw_record);
		dmsg($raw_ttl);
		dmsg($raw_val);

		/* create record list */
		$records = array();

		for($i = 0; $i < $record_count; $i++) {
			if($raw_record[5][$i] == '') {
				$h = $DEFAULT_HOST;
			} else {
				$h = strtolower($raw_record[5][$i]);
			}

			$records[$h]['no'] = $i;
			$records[$h]['type'] = $raw_record[3][$i];
			$records[$h]['ttl'] = $raw_ttl[2][$i];
			$records[$h]['val'] = $raw_val[2][$i];
		}

		dmsg($records);

		/* process each host */
		foreach($hosts as $host) {
			/* only update specified host */
			if(isset($records[$host])) {
				$records[$host]['ttl'] = $ttl;
				$records[$host]['val'] = $IP;
			}
		}

		dmsg($records);

		/* generate update cmd */
		$cmd_args = array();

		foreach($records as $host => $record) {
			$freenom_host = strtoupper(($host == '@'?'':$host));

			$arg =	'-F "records['.$record['no'].'][line]=" '.
                    '-F "records['.$record['no'].'][type]='.$record['type'].'" '.
                    '-F "records['.$record['no'].'][name]='.$freenom_host.'" '.
                    '-F "records['.$record['no'].'][ttl]='.$record['ttl'].'" '.
                    '-F "records['.$record['no'].'][value]='.$record['val'].'"';

			array_push($cmd_args, $arg);
		}

		dmsg($cmd_args);

		$cmd = 'curl --compressed -k -L -b "'.
		        $COOKIE.'" -F "dnsaction=modify" ';
		$cmd .= implode(" ", $cmd_args);
		$cmd .= ' "https://my.freenom.com/clientarea.php?managedns=';
		$cmd .= $dm.'&domainid='.$user_domains[$dm].'" 2>&1';

		/* update the domain */
		msg("Updating {$dm} ({$user_domains[$dm]})...");
		dmsg($cmd);

		exec($cmd, $result);
		$result = implode("\n", $result);

		if(!(strpos($result, '<li class=\"dnssuccess\">') === false)) {
			msg("Update {$dm} ({$user_domains[$dm]}): Failed", true);
			continue;
		}

		msg("Update {$dm} ({$user_domains[$dm]}): Done");
	}

	$cmd = 'curl --compressed -k -b "'.
	        $COOKIE.'" "https://my.freenom.com/logout.php" > /dev/null 2>&1';

	msg('Log out from Freenom');
	dmsg($cmd);
	exec($cmd);

	exec('rm -f {$COOKIE}');
	msg('Cookie cleaned.');

	closelog();

	/***************************************************************************
	 *                           Helper Function                               *
	 **************************************************************************/
	function msg($msg, $error = false) {
		global $VERBOSE, $LOG;

		if($error) {
			if($VERBOSE) echo "ERROR: $msg\n";
			if($LOG) syslog(LOG_ERR, $msg);
		} else {
			if($VERBOSE) echo "INFO: $msg\n";
			if($LOG) syslog(LOG_INFO, $msg);
		}
	}

	function dmsg($var) {
		global $DEBUG;

		if($DEBUG) {
			print_r($var);
			echo "\n";
		}
	}
?>
