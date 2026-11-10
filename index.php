<?php

function generateHAProxyConfigs($template, $proxyPorts, $otsIpAddress, $statusPort)
{
    $proxyLines = [];
    foreach ($proxyPorts as $key => $proxyPort) {
        $proxyLines[] = 'listen p' . ($key + 1);
        $proxyLines[] = '    bind 0.0.0.0:' . $proxyPort;
        $proxyLines[] = '    server srv1 ' . $otsIpAddress . ':' . $proxyPort . ' send-proxy-v2';
        $proxyLines[] = '';
    }
    $proxyConfigText = implode("\n", $proxyLines);

    $statusLines = [
            'listen statusport',
            '    bind 0.0.0.0:' . $statusPort,
            '    maxconn 100',
            '    timeout client 5s',
            '    timeout server 5s',
            '    server srv1 ' . $otsIpAddress . ':' . $statusPort . ' send-proxy-v2',
            '',
    ];
    $statusConfigText = implode("\n", $statusLines) . "\n" . $proxyConfigText;

    return [
            'no_status' => str_replace('haproxy_ports_config', $proxyConfigText, $template),
            'status' => str_replace('haproxy_ports_config', $statusConfigText, $template),
    ];
}

function parseHostEntry($entry, $defaultUser)
{
    $user = $defaultUser;
    $port = 22;
    $hostPart = $entry;

    if (strpos($entry, '@') !== false) {
        $parts = explode('@', $entry);
        if (count($parts) !== 2) {
            return null;
        }
        $user = $parts[0];
        $hostPart = $parts[1];
    }

    if (strpos($hostPart, ':') !== false) {
        $parts = explode(':', $hostPart);
        if (count($parts) !== 2) {
            return null;
        }
        $ip = $parts[0];
        $port = (int) $parts[1];
        if ($port < 1 || $port > 65535) {
            return null;
        }
    } else {
        $ip = $hostPart;
    }

    return ['user' => $user, 'ip' => $ip, 'port' => $port];
}

function addSshPortToCommands($text, $port)
{
    if ($port == 22) {
        return $text;
    }
    $lines = explode("\n", $text);
    foreach ($lines as &$line) {
        if (strpos($line, 'ssh-copy-id ') === 0) {
            $line = 'ssh-copy-id -p ' . $port . ' ' . substr($line, strlen('ssh-copy-id '));
        } elseif (strpos($line, 'ssh ') === 0) {
            $line = 'ssh -p ' . $port . ' ' . substr($line, strlen('ssh '));
        } elseif (strpos($line, 'scp ') === 0) {
            $line = 'scp -P ' . $port . ' ' . substr($line, strlen('scp '));
        }
    }
    return implode("\n", $lines);
}

function generateSshPortChangePreamble($user, $ip, $port, $certPath)
{
    if ($port == 22) {
        return '';
    }
    $userAtIp = $user . '@' . $ip;
    $lines = [
            '# --- Change SSH port from 22 to ' . $port . ' ---',
            '',
            'ssh ' . $userAtIp,
            'ssh-copy-id -i ' . $certPath . ' ' . $userAtIp,
            'ssh ' . $userAtIp . ' \'sudo sed -i "s/^#\\?Port .*/Port ' . $port . '/" /etc/ssh/sshd_config && sudo systemctl restart sshd\'',
            '',
            '# --- SSH port changed to ' . $port . ', commands below use new port ---',
            '',
            '',
    ];
    return implode("\n", $lines);
}

if (isset($_POST['generate'])) {
    $files = [];
    $ots_name = $_POST['ots_name'];
    $ots_ip = $_POST['ots_ip'];
    $other_ips = $_POST['other_ips'];
    $proxy_ips_input = $_POST['proxy_ips'];
    $linux_username = $_POST['linux_username'];
    $proxy_start_port = (int)$_POST['proxy_start_port'];
    $proxy_end_port = (int)$_POST['proxy_end_port'];
    $database_port = (int)$_POST['database_port'];
    $ots_status_port = (int)$_POST['ots_status_port'];
    $ots_ports = $_POST['ots_ports'];
    $ssh_cert_path = $_POST['ssh_cert_path'];
    $ws_proxy_address = isset($_POST['ws_proxy_address']) ? trim($_POST['ws_proxy_address']) : '';

    $required_fields = [
        'ots_name' => $ots_name,
        'ots_ip' => $ots_ip, 
        'proxy_ips' => $proxy_ips_input,
        'linux_username' => $linux_username,
        'proxy_start_port' => $proxy_start_port,
        'proxy_end_port' => $proxy_end_port,
        'database_port' => $database_port,
        'ots_status_port' => $ots_status_port,
        'ots_ports' => $ots_ports,
        'ssh_cert_path' => $ssh_cert_path
    ];

    foreach ($required_fields as $field_name => $field_value) {
        if (empty($field_value)) {
            echo "Please fill the field: " . $field_name;
            exit;
        }
    }
    if ($proxy_end_port - $proxy_start_port < 0) {
        echo "Proxy end port must be greater or the same as proxy start port";
        exit;
    }
    if ($proxy_end_port - $proxy_start_port > 20) {
        echo "You should not use more than 20 proxy ports";
        exit;
    }

    $ots_ip = trim($ots_ip);
    $otsParsed = parseHostEntry($ots_ip, $linux_username);
    if ($otsParsed === null) {
        echo "Invalid OTS IP format. Please use 'ip', 'ip:port', 'username@ip' or 'username@ip:port'";
        exit;
    }
    $ots_ssh_user = $otsParsed['user'];
    $ots_ip_address = $otsParsed['ip'];
    $ots_ssh_port = $otsParsed['port'];

    $other_ips = trim($other_ips);
    $proxy_ips_input = trim($proxy_ips_input);
    $linux_username = trim($linux_username);
    $ssh_cert_path = trim($ssh_cert_path);

    $proxy_ports = [];
    for ($proxy_port = $proxy_start_port; $proxy_port <= $proxy_end_port; $proxy_port++) {
        $proxy_ports[] = $proxy_port;
    }

    $all_ips = [$ots_ip_address];
    $www_servers = [];
    foreach (explode("\n", $other_ips) as $other_ip_line) {
        $other_ip_line = trim($other_ip_line);
        if (empty($other_ip_line)) {
            continue;
        }

        $wwwParsed = parseHostEntry($other_ip_line, $linux_username);
        if ($wwwParsed === null) {
            echo "Invalid WWW IP format. Please use 'ip', 'ip:port', 'username@ip' or 'username@ip:port'. Line: " . $other_ip_line;
            exit;
        }
        $all_ips[] = $wwwParsed['ip'];
        $www_servers[] = ['ip' => $wwwParsed['ip'], 'user' => $wwwParsed['user'], 'port' => $wwwParsed['port']];
    }

    $proxy_ips = [];
    $proxy_ips_usernames = [];
    $proxy_ips_ports = [];
    foreach (explode("\n", $proxy_ips_input) as $proxy_ip_input) {
        $proxy_ip_input = trim($proxy_ip_input);
        if (empty($proxy_ip_input)) {
            continue;
        }
        $proxyParsed = parseHostEntry($proxy_ip_input, $linux_username);
        if ($proxyParsed === null) {
            echo "Invalid proxy IP format. Please use 'ip', 'ip:port', 'username@ip' or 'username@ip:port'";
            exit;
        }
        $all_ips[] = $proxyParsed['ip'];
        $proxy_ips[] = $proxyParsed['ip'];
        $proxy_ips_usernames[] = $proxyParsed['user'] . '@' . $proxyParsed['ip'];
        $proxy_ips_ports[] = $proxyParsed['port'];
    }
    foreach ($all_ips as $ip) {
        $ipParts = explode('.', $ip);
        if (count($ipParts) !== 4) {
            echo "Invalid IP format. Please use the format '1.1.1.1', IP: " . $ip;
            exit;
        }
    }

    foreach ($proxy_ips as $key => $proxy_ip) {
        $pID = $key + 1;
        if ($pID < 10) {
            $pID = '0' . $pID;
        }
        $proxy_ip_username = $proxy_ips_usernames[$key];
        $proxy_ssh_port = $proxy_ips_ports[$key];
        $installTxt = file_get_contents('files/install.txt');
        $installTxt = str_replace("linuxuser@proxy_ip", $proxy_ip_username, $installTxt);
        $installTxt = str_replace("cert_path", $ssh_cert_path, $installTxt);
        $installTxt = str_replace("otsname", $ots_name, $installTxt);
        $installTxt = str_replace("pID", $pID, $installTxt);

        $haproxyFile = ($key === 0) ? 'haproxy_status.cfg' : 'haproxy_no_status.cfg';
        $installTxt = str_replace("files/haproxy.cfg", "files/" . $haproxyFile, $installTxt);
        $installTxt = str_replace("mv haproxy.cfg", "mv " . $haproxyFile, $installTxt);

        $installTxt = addSshPortToCommands($installTxt, $proxy_ssh_port);
        $preamble = generateSshPortChangePreamble(
            explode('@', $proxy_ip_username)[0],
            $proxy_ip,
            $proxy_ssh_port,
            $ssh_cert_path
        );
        $files['p' . $pID . '.txt'] = $preamble . $installTxt;
    }

    $installServerTxt = file_get_contents('files/install_server.txt');

    // OTS
    $otsInstall = $installServerTxt . "\n" . file_get_contents('files/install_ots_proxy.txt');
    $otsInstall = str_replace("linuxuser", $ots_ssh_user, $otsInstall);
    $otsInstall = str_replace("server_ip", $ots_ip_address, $otsInstall);
    $otsInstall = str_replace("cert_path", $ssh_cert_path, $otsInstall);
    $otsInstall = str_replace("script_mode", "ots", $otsInstall);
    $otsInstall = addSshPortToCommands($otsInstall, $ots_ssh_port);
    $otsPreamble = generateSshPortChangePreamble($ots_ssh_user, $ots_ip_address, $ots_ssh_port, $ssh_cert_path);
    $files['ots.txt'] = $otsPreamble . $otsInstall;

    // WWW
    $wwwCount = 0;
    foreach ($www_servers as $server) {
        $wwwCount++;
        $wwwInstall = $installServerTxt;
        $wwwInstall = str_replace("linuxuser", $server['user'], $wwwInstall);
        $wwwInstall = str_replace("server_ip", $server['ip'], $wwwInstall);
        $wwwInstall = str_replace("cert_path", $ssh_cert_path, $wwwInstall);
        $wwwInstall = str_replace("script_mode", "www", $wwwInstall);
        $wwwInstall = addSshPortToCommands($wwwInstall, $server['port']);
        $wwwPreamble = generateSshPortChangePreamble($server['user'], $server['ip'], $server['port'], $ssh_cert_path);

        $fileName = ($wwwCount === 1) ? 'www.txt' : 'www_' . $wwwCount . '.txt';
        $files[$fileName] = $wwwPreamble . $wwwInstall;
    }

    $firewallSh = file_get_contents('files/firewall.sh');
    $firewallIpsList = [];
    foreach ($all_ips as $key => $ip) {
        $firewallIpsList[] = 'iptables -A INPUT -s ' . $ip . '/32 -j ACCEPT';
    }
    $firewallSh = str_replace("ips_with_unlimited_access", implode("\n", $firewallIpsList), $firewallSh);
    $firewallSh = str_replace("ots_status_port", $ots_status_port, $firewallSh);
    $firewallSh = str_replace("ots_ports", $ots_ports, $firewallSh);
    $firewallSh = str_replace("database_port", $database_port, $firewallSh);
    $firewallSh = str_replace("proxy_start_port", $proxy_start_port, $firewallSh);
    $firewallSh = str_replace("proxy_end_port", $proxy_end_port, $firewallSh);
    $files['files/firewall.sh'] = $firewallSh;

    $haproxyCfgTemplate = file_get_contents('files/haproxy.cfg');
    $haproxyConfigs = generateHAProxyConfigs($haproxyCfgTemplate, $proxy_ports, $ots_ip_address, $ots_status_port);
    $files['files/haproxy_no_status.cfg'] = $haproxyConfigs['no_status'];
    $files['files/haproxy_status.cfg'] = $haproxyConfigs['status'];

    $startShLines = ['#!/bin/bash'];
    foreach ($proxy_ports as $key => $proxy_port) {
        $portId = $key + 1;
        $startShLines[] = "screen -S proxy_" . $portId . " -d -m './proxy_" . $portId . ".sh'";


        $proxyFile = file_get_contents('files/proxy/proxy.sh');
        $proxyFile = str_replace("proxy_port", $proxy_port, $proxyFile);

        $files['files/proxy/proxy_' . $portId . '.sh'] = $proxyFile;
    }
    $files['files/proxy/start.sh'] = implode("\n", $startShLines);

    $files['files/proxy/alpha-proxy/CMakeLists.txt'] = file_get_contents('files/proxy/alpha-proxy/CMakeLists.txt');
    $files['files/proxy/alpha-proxy/main.cpp'] = file_get_contents('files/proxy/alpha-proxy/main.cpp');
    $files['files/proxy/alpha-proxy/server.cpp'] = file_get_contents('files/proxy/alpha-proxy/server.cpp');
    $files['files/proxy/alpha-proxy/server.h'] = file_get_contents('files/proxy/alpha-proxy/server.h');
    $files['files/proxy/installation-of-proxy.md'] = file_get_contents('files/proxy/installation-of-proxy.md');

    // Generates otcv8.lua file for OTCv8 client
    $otcv8LuaLines = [];
    $otcv8LuaLines[] = 'math.randomseed(os.time())';
    $otcv8LuaLines[] = '';
    $otcv8LuaLines[] = 'vpsPort = math.random(' . $proxy_start_port . ',' . $proxy_end_port . ')';
    if ($ws_proxy_address !== '') {
        $otcv8LuaLines[] = 'g_proxy.addProxy("' . $ws_proxy_address . '" .. vpsPort, 80, 0)';
        foreach ($proxy_ips as $proxy_ip) {
            $otcv8LuaLines[] = 'g_proxy.addProxy("' . $proxy_ip . '", vpsPort, 50)';
        }
    } else {
        foreach ($proxy_ips as $proxy_ip) {
            $otcv8LuaLines[] = 'g_proxy.addProxy("' . $proxy_ip . '", vpsPort, 0)';
        }
    }
    $files['otcv8_init.lua'] = implode("\n", $otcv8LuaLines);

    // Generates proxy.config file for OTCv8 proxy 'standalone' client (RL Tibia client)
    $proxyConfigLines = [];
    foreach ($proxy_ips as $proxy_ip) {
        $proxyConfigLines[] = $proxy_ip . ' ' . $proxy_ip . ' ' . $proxy_start_port;
    }
    $files['proxy.config'] = implode("\n", $proxyConfigLines);

    // Generates JSON configuration file compatible with JavaScript import
    $jsonConfig = [
        'ots_name' => $ots_name,
        'ots_ip' => $ots_ip,
        'other_ips' => $other_ips,
        'proxy_ips' => $proxy_ips_input,
        'linux_username' => $linux_username,
        'proxy_start_port' => $proxy_start_port,
        'proxy_end_port' => $proxy_end_port,
        'database_port' => $database_port,
        'ots_status_port' => $ots_status_port,
        'ots_ports' => $ots_ports,
        'ssh_cert_path' => $ssh_cert_path,
        'ws_proxy_address' => $ws_proxy_address,
    ];
    $files['config.json'] = json_encode($jsonConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    // Generate SSH client config file for all servers
    $sshIdentityFile = $ssh_cert_path;
    if (substr($sshIdentityFile, -4) === '.pub') {
        $sshIdentityFile = substr($sshIdentityFile, 0, -4);
    }

    $sshConfigServers = [];
    $sshConfigSeenIps = [];

    $sshConfigServers[] = ['ip' => $ots_ip_address, 'user' => $ots_ssh_user, 'port' => $ots_ssh_port];
    $sshConfigSeenIps[] = $ots_ip_address;

    foreach ($www_servers as $server) {
        if (!in_array($server['ip'], $sshConfigSeenIps)) {
            $sshConfigServers[] = ['ip' => $server['ip'], 'user' => $server['user'], 'port' => $server['port']];
            $sshConfigSeenIps[] = $server['ip'];
        }
    }

    foreach ($proxy_ips as $key => $proxy_ip) {
        if (!in_array($proxy_ip, $sshConfigSeenIps)) {
            $proxyUser = explode('@', $proxy_ips_usernames[$key])[0];
            $sshConfigServers[] = ['ip' => $proxy_ip, 'user' => $proxyUser, 'port' => $proxy_ips_ports[$key]];
            $sshConfigSeenIps[] = $proxy_ip;
        }
    }

    $sshConfigLines = [];
    foreach ($sshConfigServers as $server) {
        $sshConfigLines[] = 'Host ' . $server['ip'];
        $sshConfigLines[] = '    HostName ' . $server['ip'];
        $sshConfigLines[] = '    User ' . $server['user'];
        if ($server['port'] != 22) {
            $sshConfigLines[] = '    Port ' . $server['port'];
        }
        $sshConfigLines[] = '    IdentityFile ' . $sshIdentityFile;
        $sshConfigLines[] = '';
    }
    $files['ssh_client.txt'] = implode("\n", $sshConfigLines);

    $zipname = sha1(microtime(true)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipname, ZipArchive::CREATE) !== TRUE) {
        exit("Cannot create zip file");
    }

    foreach ($files as $filepath => $content) {
        $zip->addFromString($filepath, $content);
    }
    $zip->close();

    $filename = $ots_name . '_proxy_config_' . date('Y-m-d_H-i-s') . '.zip';
    
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($zipname));
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

    readfile($zipname);
    unlink($zipname);
    exit;
}

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Proxy configuration generator</title>
    <style>
        :root {
            --bg: #0f1419;
            --surface: #1a1f26;
            --border: #2d333b;
            --text: #e6edf3;
            --muted: #8b949e;
            --accent: #3b82f6;
            --accent-hover: #2563eb;
            --danger: #f85149;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 14px 16px;
            font-family: system-ui, -apple-system, Segoe UI, sans-serif;
            font-size: 14px;
            line-height: 1.35;
            color: var(--text);
            background: var(--bg);
        }
        h1 {
            margin: 0 0 10px;
            font-size: 1.25rem;
            font-weight: 600;
        }
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
        }
        .toolbar-hint {
            color: var(--muted);
            font-size: 13px;
            font-style: italic;
        }
        hr {
            border: none;
            border-top: 1px solid var(--border);
            margin: 12px 0;
        }
        label {
            display: block;
            margin-bottom: 4px;
            color: var(--muted);
            font-size: 13px;
        }
        .field { margin-bottom: 10px; }
        .field-label-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 5px;
            margin-bottom: 4px;
            width: fit-content;
            max-width: 100%;
        }
        .field-label-row label {
            margin-bottom: 0;
            flex: 0 0 auto;
        }
        .field-label-row button {
            flex: 0 0 auto;
            padding: 4px 10px;
            font-size: 13px;
            background: var(--border);
            color: var(--text);
        }
        .field-label-row button:hover {
            background: #484f58;
        }
        input[type="text"],
        input[type="number"],
        textarea {
            width: 100%;
            max-width: 420px;
            padding: 6px 10px;
            font-size: 14px;
            color: var(--text);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
        }
        input:focus, textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25);
        }
        textarea { resize: vertical; min-height: 52px; }
        button, input[type="submit"] {
            padding: 6px 14px;
            font-size: 14px;
            color: #fff;
            background: var(--accent);
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        button:hover, input[type="submit"]:hover {
            background: var(--accent-hover);
        }
        button:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        .ports-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 10px;
        }
        .ports-row > div label { margin-bottom: 2px; }
        .ports-row input[type="number"],
        .ports-row input[type="text"] {
            max-width: none;
        }
        .ports-row .port-narrow {
            width: calc(6ch + 3.5rem);
            min-width: calc(6ch + 3.5rem);
        }
        .ports-row .port-range { width: 96px; }
        .ports-row .port-cert { width: 200px; max-width: 100%; }
        .hint {
            margin: 4px 0 10px;
            color: var(--muted);
            font-size: 12px;
        }
        .proxy-list {
            max-width: 520px;
            max-height: 280px;
            overflow-y: auto;
            margin-bottom: 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--surface);
        }
        .proxy-list-empty {
            padding: 12px;
            color: var(--muted);
            font-size: 13px;
            text-align: center;
        }
        .proxy-row {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-bottom: 1px solid var(--border);
        }
        .proxy-row:last-child { border-bottom: none; }
        .proxy-row:hover { background: #21262d; }
        .proxy-row.proxy-ip-first-dup {
            background: rgba(234, 179, 8, 0.22);
        }
        .proxy-row.proxy-ip-first-dup:hover {
            background: rgba(234, 179, 8, 0.32);
        }
        .proxy-row.proxy-ip-dup {
            background: rgba(248, 81, 73, 0.22);
        }
        .proxy-row.proxy-ip-dup:hover {
            background: rgba(248, 81, 73, 0.32);
        }
        .proxy-index {
            flex-shrink: 0;
            width: 22px;
            color: var(--muted);
            font-size: 12px;
            text-align: right;
        }
        .proxy-value {
            flex: 1;
            min-width: 0;
            padding: 4px 6px;
            cursor: pointer;
            border-radius: 4px;
            word-break: break-all;
        }
        .proxy-value:hover { background: rgba(59, 130, 246, 0.1); }
        .proxy-row input.proxy-edit {
            flex: 1;
            min-width: 0;
            max-width: none;
            margin: 0;
        }
        .proxy-actions {
            display: flex;
            flex-shrink: 0;
            gap: 2px;
        }
        .proxy-actions button {
            padding: 2px 8px;
            font-size: 13px;
            line-height: 1.2;
            background: var(--border);
            color: var(--text);
        }
        .proxy-actions button:hover { background: #484f58; }
        .proxy-actions .btn-remove:hover { background: var(--danger); color: #fff; }
        .proxy-add-row {
            display: flex;
            gap: 8px;
            max-width: 520px;
            margin-bottom: 4px;
        }
        .proxy-add-row input {
            flex: 1;
            max-width: none;
        }
        a { color: var(--accent); }
        a:hover { color: var(--accent-hover); }
        .footer { margin-top: 12px; color: var(--muted); font-size: 13px; }
    </style>
</head>
<body>

<h1>Proxy configuration generator</h1>

<div class="toolbar">
    <button type="button" onclick="saveToJSON()">Save to JSON</button>
    <input type="file" id="json_file_input" accept=".json" style="display: none;" onchange="loadFromJSON(event)">
    <button type="button" onclick="document.getElementById('json_file_input').click()">Load from JSON</button>
    <span class="toolbar-hint">You can drop JSON config file anywhere on website, to make it load config.</span>
</div>

<hr>

<form id="config_form" method="post">
    <div class="field">
        <label for="ots_name">OTS name (for proxy hostname):</label>
        <input id="ots_name" name="ots_name" type="text" placeholder="myots"/>
    </div>

    <div class="field">
        <div class="field-label-row">
            <label for="ots_ip">OTS IP (or user@ip, ip:port, user@ip:port):</label>
            <button type="button" id="ots_random_port_btn">Random port</button>
        </div>
        <input id="ots_ip" name="ots_ip" type="text" placeholder="ubuntu@1.1.1.1"/>
    </div>

    <div class="field">
        <div class="field-label-row">
            <label for="other_ips">Other IPs with unlimited access (ex. www server to let it connect to database) (or user@ip, ip:port, user@ip:port):</label>
            <button type="button" id="other_ips_random_port_btn">Random port</button>
        </div>
        <textarea id="other_ips" name="other_ips" rows="2" placeholder="ubuntu@2.2.2.2"></textarea>
    </div>

    <div class="field">
        <div class="field-label-row">
            <label for="proxy_ips_add">Proxy IPs (with username and/or SSH port, if different for some IPs):</label>
            <button type="button" id="proxy_random_port_btn">Random port</button>
        </div>
        <div id="proxy_ips_list" class="proxy-list" role="list"></div>
        <div class="proxy-add-row">
            <input type="text" id="proxy_ips_add" placeholder="ubuntu@1.2.3.4:22" autocomplete="off">
            <button type="button" id="proxy_ips_add_btn">Add</button>
        </div>
        <input type="hidden" id="proxy_ips" name="proxy_ips" value="">
        <p class="hint">Only the first proxy on the list proxies "OTS status port". You can use that IP to add server to otservlist etc. Click an entry to edit.</p>
    </div>

    <div class="field">
        <label for="linux_username">Proxy default Linux username:</label>
        <input id="linux_username" name="linux_username" type="text" value="ubuntu"/>
    </div>

    <div class="ports-row">
        <div>
            <label for="proxy_start_port">Proxy start port:</label>
            <input type="number" class="port-narrow" id="proxy_start_port" name="proxy_start_port" value="6501">
        </div>
        <div>
            <label for="proxy_end_port">Proxy end port:</label>
            <input type="number" class="port-narrow" id="proxy_end_port" name="proxy_end_port" value="6509">
        </div>
        <div>
            <label for="database_port">Database port:</label>
            <input type="number" class="port-narrow" id="database_port" name="database_port" value="3306">
        </div>
        <div>
            <label for="ots_status_port">OTS status port:</label>
            <input type="number" class="port-narrow" id="ots_status_port" name="ots_status_port" value="7171">
        </div>
        <div>
            <label for="ots_ports">OTS ports range:</label>
            <input type="text" class="port-range" id="ots_ports" name="ots_ports" value="7171:7172">
        </div>
        <div>
            <label for="ssh_cert_path">SSH cert path:</label>
            <input type="text" class="port-cert" id="ssh_cert_path" name="ssh_cert_path" value="~/.ssh/id_rsa.pub"/>
        </div>
        <div>
            <label for="ws_proxy_address">WebSocket proxy:</label>
            <input type="text" style="width: 50ch;" id="ws_proxy_address" name="ws_proxy_address"
                   placeholder="ws://socket.xxx.com/?token=x" value=""/>
        </div>
    </div>

    <input type="submit" name="generate" value="Generate">
</form>

<p class="footer">Code available on <a href="https://github.com/gesior/otcv8-proxy-config">https://github.com/gesior/otcv8-proxy-config</a></p>

<script>
    const proxyItems = [];
    let editingIndex = -1;

    function syncProxyIpsHidden() {
        document.getElementById('proxy_ips').value = proxyItems.join('\n');
    }

    function extractProxyIp(entry) {
        const at = entry.lastIndexOf('@');
        var ipPart = (at !== -1) ? entry.slice(at + 1).trim() : entry.trim();
        var colon = ipPart.lastIndexOf(':');
        if (colon !== -1) {
            return ipPart.slice(0, colon);
        }
        return ipPart;
    }

    function hasHostEntryPort(entry) {
        const at = entry.lastIndexOf('@');
        const hostPart = (at !== -1) ? entry.slice(at + 1).trim() : entry.trim();
        return hostPart.indexOf(':') !== -1;
    }

    function appendPortToHostEntry(entry, port) {
        return entry.trim() + ':' + port;
    }

    function randomSshPort(min, max) {
        return Math.floor(Math.random() * (max - min + 1)) + min;
    }

    const SSH_RANDOM_PORT_MIN = 50000;
    const SSH_RANDOM_PORT_MAX = 60000;

    function assignRandomPortToOtsIp() {
        const input = document.getElementById('ots_ip');
        const entry = input.value.trim();
        if (entry === '' || hasHostEntryPort(entry)) {
            return;
        }
        input.value = appendPortToHostEntry(entry, randomSshPort(SSH_RANDOM_PORT_MIN, SSH_RANDOM_PORT_MAX));
    }

    function assignRandomPortsToOtherIps() {
        const textarea = document.getElementById('other_ips');
        const lines = textarea.value.split('\n');
        let changed = false;

        const newLines = lines.map(function (line) {
            const trimmed = line.trim();
            if (trimmed === '' || hasHostEntryPort(trimmed)) {
                return line;
            }
            changed = true;
            return appendPortToHostEntry(trimmed, randomSshPort(SSH_RANDOM_PORT_MIN, SSH_RANDOM_PORT_MAX));
        });

        if (changed) {
            textarea.value = newLines.join('\n');
        }
    }

    function assignRandomPortsToProxies() {
        let changed = false;

        proxyItems.forEach(function (entry, index) {
            if (!hasHostEntryPort(entry)) {
                proxyItems[index] = appendPortToHostEntry(entry, randomSshPort(SSH_RANDOM_PORT_MIN, SSH_RANDOM_PORT_MAX));
                changed = true;
            }
        });

        if (changed) {
            editingIndex = -1;
            renderProxyList();
            syncProxyIpsHidden();
        }
    }

    function getProxyDuplicateFlags() {
        const firstIndexByIp = {};
        const flags = proxyItems.map(function () {
            return { isFirstDup: false, isRepeatDup: false };
        });

        proxyItems.forEach(function (value, index) {
            const ip = extractProxyIp(value);
            if (firstIndexByIp[ip] === undefined) {
                firstIndexByIp[ip] = index;
            } else {
                flags[index].isRepeatDup = true;
                flags[firstIndexByIp[ip]].isFirstDup = true;
            }
        });

        return flags;
    }

    function setProxyItemsFromText(text) {
        proxyItems.length = 0;
        if (text) {
            text.split('\n').forEach(function (line) {
                line = line.trim();
                if (line) {
                    proxyItems.push(line);
                }
            });
        }
        editingIndex = -1;
        renderProxyList();
        syncProxyIpsHidden();
    }

    function renderProxyList() {
        const container = document.getElementById('proxy_ips_list');
        container.innerHTML = '';

        if (proxyItems.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'proxy-list-empty';
            empty.textContent = 'No proxy servers yet. Add one below.';
            container.appendChild(empty);
            return;
        }

        const duplicateFlags = getProxyDuplicateFlags();

        proxyItems.forEach(function (value, index) {
            const row = document.createElement('div');
            row.className = 'proxy-row';
            if (duplicateFlags[index].isFirstDup) {
                row.classList.add('proxy-ip-first-dup');
            }
            if (duplicateFlags[index].isRepeatDup) {
                row.classList.add('proxy-ip-dup');
            }
            row.setAttribute('role', 'listitem');

            const num = document.createElement('span');
            num.className = 'proxy-index';
            num.textContent = (index + 1) + '.';

            const actions = document.createElement('div');
            actions.className = 'proxy-actions';

            const btnUp = document.createElement('button');
            btnUp.type = 'button';
            btnUp.textContent = '\u2191';
            btnUp.title = 'Move up';
            btnUp.disabled = index === 0;
            btnUp.addEventListener('click', function (e) {
                e.stopPropagation();
                moveProxyItem(index, -1);
            });

            const btnDown = document.createElement('button');
            btnDown.type = 'button';
            btnDown.textContent = '\u2193';
            btnDown.title = 'Move down';
            btnDown.disabled = index === proxyItems.length - 1;
            btnDown.addEventListener('click', function (e) {
                e.stopPropagation();
                moveProxyItem(index, 1);
            });

            const btnRemove = document.createElement('button');
            btnRemove.type = 'button';
            btnRemove.className = 'btn-remove';
            btnRemove.textContent = '\u00d7';
            btnRemove.title = 'Remove';
            btnRemove.addEventListener('click', function (e) {
                e.stopPropagation();
                removeProxyItem(index);
            });

            actions.appendChild(btnUp);
            actions.appendChild(btnDown);
            actions.appendChild(btnRemove);

            if (editingIndex === index) {
                const editInput = document.createElement('input');
                editInput.type = 'text';
                editInput.className = 'proxy-edit';
                editInput.value = value;
                editInput.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
                editInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        commitEdit(index, editInput.value);
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        editingIndex = -1;
                        renderProxyList();
                    }
                });
                editInput.addEventListener('blur', function () {
                    commitEdit(index, editInput.value);
                });
                row.appendChild(num);
                row.appendChild(editInput);
                row.appendChild(actions);
                container.appendChild(row);
                editInput.focus();
                editInput.select();
            } else {
                const valueSpan = document.createElement('span');
                valueSpan.className = 'proxy-value';
                valueSpan.textContent = value;
                valueSpan.title = 'Click to edit';
                valueSpan.addEventListener('click', function () {
                    editingIndex = index;
                    renderProxyList();
                });
                row.appendChild(num);
                row.appendChild(valueSpan);
                row.appendChild(actions);
                container.appendChild(row);
            }
        });
    }

    function commitEdit(index, newValue) {
        newValue = newValue.trim();
        if (newValue === '') {
            editingIndex = -1;
            renderProxyList();
            return;
        }
        proxyItems[index] = newValue;
        editingIndex = -1;
        renderProxyList();
        syncProxyIpsHidden();
    }

    function parseProxyIpEntries(text) {
        return text.split(/[\s\r\n]+/).map(function (entry) {
            return entry.trim();
        }).filter(function (entry) {
            return entry.length > 0;
        });
    }

    function addProxyItem() {
        const input = document.getElementById('proxy_ips_add');
        const entries = parseProxyIpEntries(input.value);
        if (entries.length === 0) {
            return;
        }
        entries.forEach(function (entry) {
            proxyItems.push(entry);
        });
        input.value = '';
        editingIndex = -1;
        renderProxyList();
        syncProxyIpsHidden();
        input.focus();
    }

    function removeProxyItem(index) {
        proxyItems.splice(index, 1);
        if (editingIndex === index) {
            editingIndex = -1;
        } else if (editingIndex > index) {
            editingIndex--;
        }
        renderProxyList();
        syncProxyIpsHidden();
    }

    function moveProxyItem(index, direction) {
        const newIndex = index + direction;
        if (newIndex < 0 || newIndex >= proxyItems.length) {
            return;
        }
        const temp = proxyItems[index];
        proxyItems[index] = proxyItems[newIndex];
        proxyItems[newIndex] = temp;
        if (editingIndex === index) {
            editingIndex = newIndex;
        } else if (editingIndex === newIndex) {
            editingIndex = index;
        }
        renderProxyList();
        syncProxyIpsHidden();
    }

    document.getElementById('proxy_ips_add_btn').addEventListener('click', addProxyItem);
    document.getElementById('ots_random_port_btn').addEventListener('click', assignRandomPortToOtsIp);
    document.getElementById('other_ips_random_port_btn').addEventListener('click', assignRandomPortsToOtherIps);
    document.getElementById('proxy_random_port_btn').addEventListener('click', assignRandomPortsToProxies);
    document.getElementById('proxy_ips_add').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addProxyItem();
        }
    });

    document.getElementById('config_form').addEventListener('submit', function (e) {
        syncProxyIpsHidden();
        if (proxyItems.length === 0) {
            e.preventDefault();
            alert('Please add at least one proxy IP.');
        }
    });

    function saveToJSON() {
        syncProxyIpsHidden();
        const formData = {
            ots_name: document.getElementById('ots_name').value,
            ots_ip: document.getElementById('ots_ip').value,
            other_ips: document.getElementById('other_ips').value,
            proxy_ips: document.getElementById('proxy_ips').value,
            linux_username: document.getElementById('linux_username').value,
            proxy_start_port: document.getElementById('proxy_start_port').value,
            proxy_end_port: document.getElementById('proxy_end_port').value,
            database_port: document.getElementById('database_port').value,
            ots_status_port: document.getElementById('ots_status_port').value,
            ots_ports: document.getElementById('ots_ports').value,
            ssh_cert_path: document.getElementById('ssh_cert_path').value,
            ws_proxy_address: document.getElementById('ws_proxy_address').value
        };

        const now = new Date();
        let isoDate = now.toISOString();
        isoDate = isoDate.replace('T', ' ').replace('_', '-').replace('Z', '').split('.')[0];
        let filename = document.getElementById('ots_name').value || 'noname';
        filename = filename + '_' + isoDate;
        const jsonString = JSON.stringify(formData, null, 2);

        const blob = new Blob([jsonString], {type: 'application/json'});
        const url = URL.createObjectURL(blob);

        const a = document.createElement('a');
        a.href = url;
        a.download = filename + '.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function loadConfigFromFile(file) {
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function (e) {
            try {
                const formData = JSON.parse(e.target.result);

                if (formData.ots_name !== undefined) document.getElementById('ots_name').value = formData.ots_name;
                if (formData.ots_ip !== undefined) document.getElementById('ots_ip').value = formData.ots_ip;
                if (formData.other_ips !== undefined) document.getElementById('other_ips').value = formData.other_ips;
                if (formData.proxy_ips !== undefined) setProxyItemsFromText(formData.proxy_ips);
                if (formData.linux_username !== undefined) document.getElementById('linux_username').value = formData.linux_username;
                if (formData.proxy_start_port !== undefined) document.getElementById('proxy_start_port').value = formData.proxy_start_port;
                if (formData.proxy_end_port !== undefined) document.getElementById('proxy_end_port').value = formData.proxy_end_port;
                if (formData.database_port !== undefined) document.getElementById('database_port').value = formData.database_port;
                if (formData.ots_status_port !== undefined) document.getElementById('ots_status_port').value = formData.ots_status_port;
                if (formData.ots_ports !== undefined) document.getElementById('ots_ports').value = formData.ots_ports;
                if (formData.ssh_cert_path !== undefined) document.getElementById('ssh_cert_path').value = formData.ssh_cert_path;
                if (formData.ws_proxy_address !== undefined) document.getElementById('ws_proxy_address').value = formData.ws_proxy_address;
            } catch (error) {
                alert('Błąd podczas wczytywania pliku JSON: ' + error.message);
            }
        };
        reader.readAsText(file);
    }

    function loadFromJSON(event) {
        const file = event.target.files[0];
        loadConfigFromFile(file);
        event.target.value = '';
    }

    document.addEventListener('dragover', function(event) {
        event.preventDefault();
        event.stopPropagation();
        event.dataTransfer.dropEffect = 'copy';
    });

    document.addEventListener('drop', function(event) {
        event.preventDefault();
        event.stopPropagation();

        const files = event.dataTransfer.files;
        if (files.length > 0) {
            const file = files[0];
            if (file.name.toLowerCase().endsWith('.json') || file.type === 'application/json') {
                loadConfigFromFile(file);
            }
        }
    });

    renderProxyList();
    syncProxyIpsHidden();
</script>

</body>
</html>
