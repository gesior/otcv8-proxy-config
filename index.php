<?php

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
    $ots_ip_address = $ots_ip;
    $ots_ssh_user = $linux_username;

    if (strpos($ots_ip, '@') !== false) {
        $parts = explode('@', $ots_ip);
        if (count($parts) === 2) {
            $ots_ssh_user = $parts[0];
            $ots_ip_address = $parts[1];
        } else {
            echo "Invalid OTS IP format. Please use 'ip' or 'username@ip'";
            exit;
        }
    }

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
        
        $www_user = $linux_username;
        $www_ip_address = $other_ip_line;

        if (strpos($other_ip_line, '@') !== false) {
            $parts = explode('@', $other_ip_line);
            if (count($parts) === 2) {
                $www_user = $parts[0];
                $www_ip_address = $parts[1];
            } else {
                echo "Invalid WWW IP format. Please use 'ip' or 'username@ip'. Line: " . $other_ip_line;
                exit;
            }
        }
        $all_ips[] = $www_ip_address;
        $www_servers[] = ['ip' => $www_ip_address, 'user' => $www_user];
    }

    $proxy_ips = [];
    $proxy_ips_usernames = [];
    foreach (explode("\n", $proxy_ips_input) as $proxy_ip_input) {
        $proxy_ip_input = trim($proxy_ip_input);
        if (empty($proxy_ip_input)) {
            continue;
        }
        $proxy_ip_data = explode('@', $proxy_ip_input);
        if (count($proxy_ip_data) === 2) {
            $all_ips[] = $proxy_ip_data[1];
            $proxy_ips[] = $proxy_ip_data[1];
            $proxy_ips_usernames[] = $proxy_ip_input;
        } elseif (count($proxy_ip_data) === 1) {
            $all_ips[] = $proxy_ip_data[0];
            $proxy_ips[] = $proxy_ip_data[0];
            $proxy_ips_usernames[] = $linux_username . '@' . $proxy_ip_data[0];
        } else {
            echo "Invalid proxy IP format. Please use the format 'username@ip' or 'ip'";;
            exit;
        }
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
        $installTxt = file_get_contents('files/install.txt');
        $installTxt = str_replace("linuxuser@proxy_ip", $proxy_ip_username, $installTxt);
        $installTxt = str_replace("cert_path", $ssh_cert_path, $installTxt);
        $installTxt = str_replace("otsname", $ots_name, $installTxt);
        $installTxt = str_replace("pID", $pID, $installTxt);

        $haproxyFile = ($key === 0) ? 'haproxy_status.cfg' : 'haproxy_no_status.cfg';
        $installTxt = str_replace("files/haproxy.cfg", "files/" . $haproxyFile, $installTxt);
        $installTxt = str_replace("mv haproxy.cfg", "mv " . $haproxyFile, $installTxt);

        $files['p' . $pID . '.txt'] = $installTxt;
    }

    $installServerTxt = file_get_contents('files/install_server.txt');

    // OTS
    $otsInstall = $installServerTxt;
    $otsInstall = str_replace("linuxuser", $ots_ssh_user, $otsInstall);
    $otsInstall = str_replace("server_ip", $ots_ip_address, $otsInstall);
    $otsInstall = str_replace("cert_path", $ssh_cert_path, $otsInstall);
    $otsInstall = str_replace("script_mode", "ots", $otsInstall);
    $files['ots.txt'] = $otsInstall;

    // WWW
    $wwwCount = 0;
    foreach ($www_servers as $server) {
        $wwwCount++;
        $wwwInstall = $installServerTxt;
        $wwwInstall = str_replace("linuxuser", $server['user'], $wwwInstall);
        $wwwInstall = str_replace("server_ip", $server['ip'], $wwwInstall);
        $wwwInstall = str_replace("cert_path", $ssh_cert_path, $wwwInstall);
        $wwwInstall = str_replace("script_mode", "www", $wwwInstall);

        $fileName = ($wwwCount === 1) ? 'www.txt' : 'www_' . $wwwCount . '.txt';
        $files[$fileName] = $wwwInstall;
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

    // Configuration lines for proxy ports (shared between both configs)
    $haproxyCfgLinesCommon = [];
    foreach ($proxy_ports as $key => $proxy_port) {
        $haproxyCfgLinesCommon[] = 'listen p' . ($key + 1);
        $haproxyCfgLinesCommon[] = '    bind 0.0.0.0:' . $proxy_port;
        $haproxyCfgLinesCommon[] = '    mode tcp';
        $haproxyCfgLinesCommon[] = '    maxconn 300000';
        $haproxyCfgLinesCommon[] = '    timeout connect  4000';
        $haproxyCfgLinesCommon[] = '    timeout client   180000';
        $haproxyCfgLinesCommon[] = '    timeout server   180000';
        $haproxyCfgLinesCommon[] = '    server srv1 ' . $ots_ip_address . ':' . $proxy_port . ' send-proxy-v2';
        $haproxyCfgLinesCommon[] = '';
    }
    $commonConfigText = implode("\n", $haproxyCfgLinesCommon);

    // Remaining proxies configuration (without status port)
    $haproxyCfgNoStatus = str_replace("haproxy_ports_config", $commonConfigText, $haproxyCfgTemplate);
    $files['files/haproxy_no_status.cfg'] = $haproxyCfgNoStatus;

    // First proxy configuration (with status port)
    $haproxyCfgLinesStatusOnly = [];
    $haproxyCfgLinesStatusOnly[] = 'listen p0';
    $haproxyCfgLinesStatusOnly[] = '    bind 0.0.0.0:' . $ots_status_port;
    $haproxyCfgLinesStatusOnly[] = '    mode tcp';
    $haproxyCfgLinesStatusOnly[] = '    maxconn 100';
    $haproxyCfgLinesStatusOnly[] = '    timeout connect  4000';
    $haproxyCfgLinesStatusOnly[] = '    timeout client   180000';
    $haproxyCfgLinesStatusOnly[] = '    timeout server   180000';
    $haproxyCfgLinesStatusOnly[] = '    server srv1 ' . $ots_ip_address . ':' . $ots_status_port . ' send-proxy-v2';
    $haproxyCfgLinesStatusOnly[] = '';

    $statusConfigText = implode("\n", $haproxyCfgLinesStatusOnly) . "\n" . $commonConfigText;
    $haproxyCfgStatus = str_replace("haproxy_ports_config", $statusConfigText, $haproxyCfgTemplate);
    $files['files/haproxy_status.cfg'] = $haproxyCfgStatus;

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
    foreach ($proxy_ips as $proxy_ip) {
        $otcv8LuaLines[] = 'g_proxy.addProxy("' . $proxy_ip . '", vpsPort, 0)';
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
        'ssh_cert_path' => $ssh_cert_path
    ];
    $files['config.json'] = json_encode($jsonConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

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
    <title>Proxy configuration generator</title>
</head>
<body>

<div>
    <button onclick="saveToJSON()" style="margin-right: 10px;">Save to JSON</button>
    <input type="file" id="json_file_input" accept=".json" style="display: none;" onchange="loadFromJSON(event)">
    <button onclick="document.getElementById('json_file_input').click()">Load from JSON</button>
    <i>You can drop JSON config file anywhere on website, to make it load config.</i>
</div>

<hr style="margin: 20px 0;">

<form method="post">
    <label for="ots_name">OTS name (for proxy hostname):</label><br>
    <input id="ots_name" name="ots_name" size="40" placeholder="myots"/><br><br>

    <label for="ots_ip">OTS IP (or user@ip):</label><br>
    <input id="ots_ip" name="ots_ip" size="40" placeholder="ubuntu@1.1.1.1"/><br><br>

    <label for="other_ips">Other IPs with unlimited access (ex. www server to let it connect to database) (or user@ip):</label><br>
    <textarea id="other_ips" name="other_ips" rows="2" cols="40" placeholder="ubuntu@2.2.2.2"></textarea><br><br>

    <label for="proxy_ips">Proxy IPs (with username, if it's different for some IPs):</label><br>
    <textarea id="proxy_ips" name="proxy_ips" rows="15" cols="40" placeholder="ubuntu@1.2.3.4"></textarea><br>
    (Only the first proxy on the list proxies "OTS status port". You can use that IP to add server to otservlist etc.)<br><br>

    <label for="linux_username">Proxy default Linux username:</label><br>
    <input id="linux_username" name="linux_username" size="40" value="ubuntu"/><br><br>

    <div style="display: flex; gap: 20px; margin-bottom: 10px">
        <div>
            <label for="proxy_start_port">Proxy start port:</label>
            <input type="number" id="proxy_start_port" name="proxy_start_port" value="6501" style="width: 50px;">
        </div>
        <div>
            <label for="proxy_end_port">Proxy end port:</label>
            <input type="number" id="proxy_end_port" name="proxy_end_port" value="6509" style="width: 50px;">
        </div>
        <div>
            <label for="database_port">Database port:</label>
            <input type="number" id="database_port" name="database_port" value="3306" style="width: 50px;">
        </div>
        <div>
            <label for="ots_status_port">OTS status port:</label>
            <input type="number" id="ots_status_port" name="ots_status_port" value="7171" style="width: 50px;">
        </div>
        <div>
            <label for="ots_ports">OTS ports range:</label>
            <input id="ots_ports" name="ots_ports" value="7171:7172" style="width: 90px;">
        </div>
        <div>
            <label for="ssh_cert_path">SSH cert path:</label>
            <input id="ssh_cert_path" name="ssh_cert_path" size="40" value="~/.ssh/id_rsa.pub"/>
        </div>
    </div>

    <input type="submit" name="generate" value="Generate">
</form>

<br />
Code available on <a href="https://github.com/gesior/otcv8-proxy-config">https://github.com/gesior/otcv8-proxy-config</a>

<script>
    function saveToJSON() {
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
            ssh_cert_path: document.getElementById('ssh_cert_path').value
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
                if (formData.proxy_ips !== undefined) document.getElementById('proxy_ips').value = formData.proxy_ips;
                if (formData.linux_username !== undefined) document.getElementById('linux_username').value = formData.linux_username;
                if (formData.proxy_start_port !== undefined) document.getElementById('proxy_start_port').value = formData.proxy_start_port;
                if (formData.proxy_end_port !== undefined) document.getElementById('proxy_end_port').value = formData.proxy_end_port;
                if (formData.database_port !== undefined) document.getElementById('database_port').value = formData.database_port;
                if (formData.ots_status_port !== undefined) document.getElementById('ots_status_port').value = formData.ots_status_port;
                if (formData.ots_ports !== undefined) document.getElementById('ots_ports').value = formData.ots_ports;
                if (formData.ssh_cert_path !== undefined) document.getElementById('ssh_cert_path').value = formData.ssh_cert_path;
            } catch (error) {
                alert('Błąd podczas wczytywania pliku JSON: ' + error.message);
            }
        };
        reader.readAsText(file);
    }

    function loadFromJSON(event) {
        const file = event.target.files[0];
        loadConfigFromFile(file);
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
</script>

</body>
</html>
