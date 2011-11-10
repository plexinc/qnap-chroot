<?
/*
Copyright (c) 2010  QNAP Systems, Inc.  All Rights Reserved.

Manipulate Virtual Host

Usage:
./program options [arg...]
*/

$help = <<<EOD
./ program options [args...]

--set-all-enable [0,1]
--set-enable [0,1]
--set-ssl-enable [0,1]
--get-enable
--parse
--delete name port
--add name port root_dir ssl[0,1]
--output-xml
--gen-config
--reset-vhost-ssl
--reset-all
--check-vhost-port port ssl[0,1]

EOD;

$def_web_share = "/share/" . exec('/sbin/getcfg SHARE_DEF defWeb -d Qweb -f /etc/config/def_share.info') . "/";
$config_file = "/etc/config/apache/apache.conf";
$ssl_config_file = "/etc/config/apache/extra/apache-ssl.conf";
$vhost_config_file = "/etc/config/apache/extra/httpd-vhosts-user.conf";
$ssl_vhost_config_file = "/etc/config/apache/extra/httpd-ssl-vhosts-user.conf";
$comment = "#";
$ret = 0;

if ($argc < 2) {
	show_help();
	exit(-1);
}

switch ($argv[1]) {
	case "--get-enable":
		//echo "get";
		$ret = get_if_vhost_enabled();
		break;
	case "--set-all-enable":
		$enable = $argv[2];
		if ($enable != "1" && $enable != "0") {
			show_help();
			exit(-1);
		}
		$ret = set_vhost_enable($enable);
		$ret = set_ssl_vhost_enable($enable);
		break;
	case "--set-enable":
		$enable = $argv[2];
		if ($enable != "1" && $enable != "0") {
			show_help();
			exit(-1);
		}
		$ret = set_vhost_enable($enable);
		break;
	case "--set-ssl-enable":
		$enable = $argv[2];
		if ($enable != "1" && $enable != "0") {
			show_help();
			exit(-1);
		}
		$ret = set_ssl_vhost_enable($enable);
		break;
	case "--parse":
		parse(1);
		break;
	case "--delete":
		$name = $argv[2];
		$port = $argv[3];
		if ($name == "" || $port == "") {
			show_help();
			exit(-1);
		}
		$ret = delete($name, $port);
		break;
	case "--add":
		$name = $argv[2];
		$port = $argv[3];
		$root = $argv[4];
		$ssl = $argv[5];
		if ($name == "" || $port == "" || $root == "" || $ssl == "") {
			show_help();
			exit(-1);
		}
		$ret = add($name, $port, $root, $ssl);
		break;
	case "--output-xml":
		output_xml();
		break;
	case "--gen-config":
		// extract $listen, $vhost_array, $n_vhost
		extract(parse(0));
		output_vhost_conf($vhost_array);
		break;
	case "--reset-vhost-ssl":
		if (get_if_vhost_enabled() == 1) {
			set_vhost_enable(1);
			set_ssl_vhost_enable(1);
		} else {
			set_vhost_enable(0);
			set_ssl_vhost_enable(0);
		}
		break;
	case "--reset-all":
		output_vhost_conf(array());
		set_vhost_enable(0);
		set_ssl_vhost_enable(0);
		break;
	case "--check-vhost-port":
		$port = $argv[2];
		$ssl = $argv[3];
		if ($port == "" || $ssl == "") {
			show_help();
			exit(-1);
		}
		$ret = check_vhost_port($port, $ssl);
		break;
	default:
		show_help();
}

exit($ret);

function rename_conf_files()
{
	global $config_file, $vhost_config_file, $ssl_vhost_config_file;
	
	if (is_file($config_file . ".tmp")) {
		rename($config_file, $config_file . ".orig");
		rename($config_file . ".tmp", $config_file);
	}
	
	if (is_file($vhost_config_file . ".tmp")) {
		rename($vhost_config_file, $vhost_config_file . ".orig");
		rename($vhost_config_file . ".tmp", $vhost_config_file);
	}
	
	if (is_file($ssl_vhost_config_file . ".tmp")) {
		rename($ssl_vhost_config_file, $ssl_vhost_config_file . ".orig");
		rename($ssl_vhost_config_file . ".tmp", $ssl_vhost_config_file);
	}
	
}

function add($name, $port, $root, $ssl)
{
	global $def_web_share;
	// extract $listen, $vhost_array, $n_vhost
	extract(parse(0));
	
	$root = $def_web_share . $root;
	
	foreach($vhost_array as $i => $value) {
		if ($value['name'] == $name && $value['port'] == $port) {
			return -1;
		}
		if ($value['port'] == $port && $value['ssl'] != $ssl) {
			return -1;
		}
	}
	
	$vhost_array[] = array("name" => $name,
							"port" => $port,
							"root" => $root,
							"ssl" => $ssl);
							
	output_vhost_conf($vhost_array);
	
	return 0;
}

function delete($name, $port)
{
	global $def_web_share;
	// extract $listen, $vhost_array, $n_vhost
	extract(parse(0));
	
	foreach($vhost_array as $i => $value) {
		if ($value['name'] == $name && $value['port'] == $port) {
			unset($vhost_array[$i]);
			output_vhost_conf($vhost_array);
			return 0;
		}
	}
	
	return -1;
}

function _cmp($a, $b)
{
	return strcmp($a["name"], $b["name"]);
}

function output_xml()
{
	global $def_web_share, $vhost_config_file;
	// extract $listen, $vhost_array, $n_vhost
	extract(parse(0));

	usort($vhost_array, "_cmp");

	echo "<VirtualHost>\n";
	foreach ($vhost_array as $item) {
		echo "\t<VHostElement>\n";
		echo "\t\t<VHostPort><![CDATA[" . $item["port"] . "]]></VHostPort>\n";
		echo "\t\t<VHostName><![CDATA[" . $item["name"] . "]]></VHostName>\n";
		echo "\t\t<VHostPath><![CDATA[" . substr($item["root"], strlen("/share")) . "]]></VHostPath>\n";
		echo "\t\t<VHostSSL><![CDATA[" . $item["ssl"] . "]]></VHostSSL>\n";
		echo "\t</VHostElement>\n";
	}
	echo "</VirtualHost>\n";
}

function check_vhost_port($port, $ssl)
{
	// extract $listen, $vhost_array, $n_vhost
	extract(parse(0));
	
	foreach ($vhost_array as $item) {
		if ($ssl == "1") {
			if ($item["ssl"] == "0" && $item["port"] == $port) {
				return -1;
			}
		} else { // $ssl == "0"
			if ($item["ssl"] == "1" && $item["port"] == $port) {
				return -1;
			}
		}
	}
	
	return 0;
}

function remove_global_listen_ports(&$listen, $global_listen)
{
	foreach ($global_listen as $i => $g_item) {
		foreach ($listen as $j => $item) {
			if ($g_item == $item) {
				unset($listen[$j]);
				break;
			}
		}
	}
}

function output_vhost_conf($vhost_array)
{
	global $comment, $config_file, $ssl_config_file, $vhost_config_file, $ssl_vhost_config_file;
	$global_listen = array();
	$global_listen_ssl = array();
	$listen = array();
	$ssl_listen = array();
	$global_root = "";
	
	$fp = fopen($config_file, "r");
	
	while (!feof($fp)) {
		$line = fgets($fp);
		$line = trim($line);
		if ($line && !preg_match("/^$comment/", $line)) {
			if (preg_match("/^Listen/", $line)) {
				$chars = preg_split("/[ ]+/", $line);
				$global_listen[] = $chars[1];
			}
			else if (preg_match("/^DocumentRoot/", $line)) {
				$chars = preg_split("/[ ]+/", $line);
				$global_root = trim($chars[1], "\"");
			}
		}
	}
	
	fclose($fp);
	
	$fp = fopen($ssl_config_file, "r");
	
	while (!feof($fp)) {
		$line = fgets($fp);
		$line = trim($line);
		if ($line && !preg_match("/^$comment/", $line)) {
			if (preg_match("/^Listen/", $line)) {
				$chars = preg_split("/[ ]+/", $line);
				$global_listen_ssl[] = $chars[1];
				break;
			}
		}
	}
	
	fclose($fp);
	
	
	foreach ($vhost_array as $item) {
		if ($item["ssl"] == 1) {
			$ssl_listen[] = $item['port'];
		} else {
			$listen[] = $item['port'];
		}
	}
	
	$listen = array_unique($listen);
	$ssl_listen = array_unique($ssl_listen);
	
	//echo "listen\n";
	//var_dump($listen);
	
	$fp = fopen($vhost_config_file . ".tmp", "w");
	$fp_ssl = fopen($ssl_vhost_config_file . ".tmp", "w");
	
	foreach ($listen as $item) {
		fputs($fp, "NameVirtualHost *:" . $item . "\n");
	}
	foreach ($ssl_listen as $item) {
		fputs($fp_ssl, "NameVirtualHost *:" . $item . "\n");
	}
	fputs($fp, "\n");
	fputs($fp_ssl, "\n");
	
	remove_global_listen_ports($listen, $global_listen);
	remove_global_listen_ports($listen, $global_listen_ssl);
	remove_global_listen_ports($ssl_listen, $global_listen);
	remove_global_listen_ports($ssl_listen, $global_listen_ssl);
	
	foreach ($listen as $item) {
		fputs($fp, "Listen " . $item . "\n");
	}
	foreach ($ssl_listen as $item) {
		fputs($fp_ssl, "Listen " . $item . "\n");
	}
	fputs($fp, "\n");
	fputs($fp_ssl, "\n");
	
	foreach ($global_listen as $i) {
		fputs($fp, "<VirtualHost _default_:" . $i . ">\n");
		fputs($fp, "\tDocumentRoot \"" . $global_root . "\"\n");
		fputs($fp, "</VirtualHost>\n");
	}
	
	foreach ($vhost_array as $item) {
		if ($item["ssl"] == 1) {
			fputs($fp_ssl, "<VirtualHost *:" . $item["port"] . ">\n");
			fputs($fp_ssl, "\tServerName " . $item["name"] . "\n");
			fputs($fp_ssl, "\tDocumentRoot \"" . $item["root"] . "\"\n");
			fputs($fp_ssl, "\tSSLEngine on\n");
			fputs($fp_ssl, "\tSSLCipherSuite ALL:!ADH:!EXPORT56:RC4+RSA:+HIGH:+MEDIUM:+LOW:+SSLv2:+EXP\n");
			fputs($fp_ssl, "\tSSLCertificateFile \"/etc/stunnel/stunnel.pem\"\n");
			fputs($fp_ssl, "</VirtualHost>\n");
		} else {
			fputs($fp, "<VirtualHost *:" . $item["port"] . ">\n");
			fputs($fp, "\tServerName " . $item["name"] . "\n");
			fputs($fp, "\tDocumentRoot \"" . $item["root"] . "\"\n");
			fputs($fp, "</VirtualHost>\n");
		}
	}

	fclose($fp);
	fclose($fp_ssl);
	rename_conf_files();
}

function parse($output)
{
	global $comment, $vhost_config_file, $ssl_vhost_config_file;
	$n_vhost = array();
	$vhost_array = array();
	$listen = array();
	
	$is_in_block = 0;
	$vhost_index = -1;
	
	if (!file_exists($vhost_config_file)) {
		touch($vhost_config_file);
	}
	
	$fp = fopen($vhost_config_file, "r");
	
	while (!feof($fp)) {
		$line = fgets($fp);
		$line = trim($line);
		if ($line && !preg_match("/^$comment/", $line)) {
			if ($is_in_block == 0 && preg_match("/^NameVirtualHost/", $line)) {
				$chars = preg_split("/\*:/", $line);
				$n_vhost[] = $chars[1];
			}
			else if ($is_in_block == 0 && preg_match("/^Listen/", $line)) {
				$chars = preg_split("/[ ]+/", $line);
				$listen[] = $chars[1];
			}
			else if (preg_match("/^<VirtualHost/", $line)) {
				if (strstr($line, "_default")) continue;
				$vhost_index++;
				$is_in_block = 1;
				$chars = preg_split("/\*:|>/", $line);
				$vhost_array[$vhost_index]["port"] = $chars[1];
				$vhost_array[$vhost_index]["ssl"] = 0;
			}
			else if (preg_match("/^<\/VirtualHost/", $line)) {
				$is_in_block = 0;
			}
			else if ($is_in_block == 1) {
				if (preg_match("/^ServerName/", $line)) {
					$chars = preg_split("/[ ]+/", $line);
					$vhost_array[$vhost_index]["name"] = $chars[1];
				}
				else if (preg_match("/^DocumentRoot/", $line)) {
					$chars = preg_split("/[ ]+/", $line);
					$vhost_array[$vhost_index]["root"] = trim($chars[1], "\"");
				}
			}
		}
	}
	
	fclose($fp);
	
	if (!file_exists($ssl_vhost_config_file)) {
		touch($ssl_vhost_config_file);
	}
	
	$fp = fopen($ssl_vhost_config_file, "r");
	
	while (!feof($fp)) {
		$line = fgets($fp);
		$line = trim($line);
		if ($line && !preg_match("/^$comment/", $line)) {
			if ($is_in_block == 0 && preg_match("/^NameVirtualHost/", $line)) {
				$chars = preg_split("/\*:/", $line);
				$n_vhost[] = $chars[1];
			}
			else if ($is_in_block == 0 && preg_match("/^Listen/", $line)) {
				$chars = preg_split("/[ ]+/", $line);
				$listen[] = $chars[1];
			}
			else if (preg_match("/^<VirtualHost/", $line)) {
				$vhost_index++;
				$is_in_block = 1;
				$chars = preg_split("/\*:|>/", $line);
				$vhost_array[$vhost_index]["port"] = $chars[1];
				$vhost_array[$vhost_index]["ssl"] = 0;
			}
			else if (preg_match("/^<\/VirtualHost/", $line)) {
				$is_in_block = 0;
			}
			else if ($is_in_block == 1) {
				if (preg_match("/^ServerName/", $line)) {
					$chars = preg_split("/[ ]+/", $line);
					$vhost_array[$vhost_index]["name"] = $chars[1];
				}
				else if (preg_match("/^DocumentRoot/", $line)) {
					$chars = preg_split("/[ ]+/", $line);
					$vhost_array[$vhost_index]["root"] = trim($chars[1], "\"");
				}
				else if (preg_match("/^SSLEngine/", $line)) {
					$vhost_array[$vhost_index]["ssl"] = 1;
				}
			}
		}
	}
	
	fclose($fp);
	
	if ($output == 1) {
		echo "Listen\n";
		print_r($listen);
		echo "Virtual Host Array\n";
		print_r($vhost_array);
		echo "NameVirtualHost\n";
		print_r($n_vhost);
	}
	
	return compact('listen', 'vhost_array', 'n_vhost');
}

function set_ssl_vhost_enable($enable)
{
	global $comment, $config_file, $ssl_vhost_config_file;
	
	$cur_enable = get_if_ssl_vhost_enabled();
	if (get_if_ssl_enabled() == 0) $enable = 0;
	
	if ($cur_enable == 0) {
		if ($enable == 0) return 0;
		if ($enable == 1) {
			copy($config_file, $config_file . ".tmp");
			$fp = fopen($config_file . ".tmp", "r+");
			
			fseek($fp, -1, SEEK_END);
			if (fgetc($fp) != "\n") { 
				fputs($fp, "\n");
			}
			fputs($fp, "Include " . $ssl_vhost_config_file . "\n");

			fclose($fp);
			rename_conf_files();
			
			return 0;
		}
		
	} else if ($cur_enable == 1) {
		if ($enable == 1) return 0;
		if ($enable == 0) {
			$vconf = preg_quote($ssl_vhost_config_file, '/');
			
			$fp_i = fopen($config_file, "r");
			$fp_o = fopen($config_file . ".tmp", "w");
			while (!feof($fp_i)) {
				$line = fgets($fp_i);
				if ($line && !preg_match("/^$comment/", $line)) {
					if (preg_match("/^Include $vconf/", $line)) {
						continue;
					}
				}
				
				fputs($fp_o, $line);
			}

			fclose($fp_i);
			fclose($fp_o);
			rename_conf_files();
			
			return 0;
		}
	}

	return -1;
}

function set_vhost_enable($enable)
{
	global $comment, $config_file, $vhost_config_file;
	
	$cur_enable = get_if_vhost_enabled();
	
	if ($cur_enable == 0) {
		if ($enable == 0) return 0;
		if ($enable == 1) {
			copy($config_file, $config_file . ".tmp");
			$fp = fopen($config_file . ".tmp", "r+");
			
			fseek($fp, -1, SEEK_END);
			if (fgetc($fp) != "\n") { 
				fputs($fp, "\n");
			}
			fputs($fp, "Include " . $vhost_config_file . "\n");

			fclose($fp);
			rename_conf_files();
			
			return 0;
		}
		
	} else if ($cur_enable == 1) {
		if ($enable == 1) return 0;
		if ($enable == 0) {
			$vconf = preg_quote($vhost_config_file, '/');
			
			$fp_i = fopen($config_file, "r");
			$fp_o = fopen($config_file . ".tmp", "w");
			while (!feof($fp_i)) {
				$line = fgets($fp_i);
				if ($line && !preg_match("/^$comment/", $line)) {
					if (preg_match("/^Include $vconf/", $line)) {
						continue;
					}
				}
				
				fputs($fp_o, $line);
			}

			fclose($fp_i);
			fclose($fp_o);
			rename_conf_files();
			
			return 0;
		}
	}

	return -1;
}

function get_if_vhost_enabled()
{
	global $comment, $config_file, $vhost_config_file;
	
	$fp = fopen($config_file, "r");
	$vconf = preg_quote($vhost_config_file, '/');
	
	while (!feof($fp)) {
		$line = fgets($fp);
		if ($line && !preg_match("/^$comment/", $line)) {
			if (preg_match("/^Include $vconf/", $line)) {
				fclose($fp);
				//echo "1";
				return 1;
			}
		}
	}
	
	fclose($fp);
	//echo "0";
	return 0;
}

function get_if_ssl_vhost_enabled()
{
	global $comment, $config_file, $ssl_vhost_config_file;
	
	$fp = fopen($config_file, "r");
	$vconf = preg_quote($ssl_vhost_config_file, '/');
	
	while (!feof($fp)) {
		$line = fgets($fp);
		if ($line && !preg_match("/^$comment/", $line)) {
			if (preg_match("/^Include $vconf/", $line)) {
				fclose($fp);
				//echo "1";
				return 1;
			}
		}
	}
	
	fclose($fp);
	//echo "0";
	return 0;
}

function get_if_ssl_enabled()
{
	global $comment, $config_file, $ssl_config_file;
	
	$fp = fopen($config_file, "r");
	$vconf = preg_quote($ssl_config_file, '/');
	
	while (!feof($fp)) {
		$line = fgets($fp);
		if ($line && !preg_match("/^$comment/", $line)) {
			if (preg_match("/^Include $vconf/", $line)) {
				fclose($fp);
				//echo "1";
				return 1;
			}
		}
	}
	
	fclose($fp);
	//echo "0";
	return 0;
}

function show_help()
{
	global $help;
	echo $help;
}
?>
