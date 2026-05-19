# Proxy Config for OTCv8

A web-based configuration generator for OTCv8 proxy networks.

Online version: https://ots.me/proxy-config/

### Overview
This tool simplifies the process of setting up a distributed proxy architecture for OTS. It generates all the necessary scripts and configuration files required to deploy and manage multiple proxy nodes, ensuring high availability and DDoS protection.

### Key Features
- **Automated Setup:** Generates shell installation scripts for Proxy nodes, OTS server, and Web servers.
- **HAProxy Configuration:** Creates optimized HAProxy configurations with support for proxy-v2 protocol and status monitoring.
- **Security:** Automatically generates `iptables` firewall scripts to restrict access and protect the core infrastructure.
- **Client Integration:** Produces ready-to-use configuration files for OTCv8 (`otcv8_init.lua`) and standalone clients (`proxy.config`).
- **State Persistence:** Allows saving and loading the generator's state via JSON files for easy updates later.
- **Custom Proxy Engine:** Includes the source code for `alpha-proxy` (a high-performance C++ proxy).

### Usage
1. Run the `index.php` on a web server with PHP support.
2. Enter your server details (IP addresses, ports, SSH certificates).
3. Click **Generate** to receive a ZIP archive with the complete configuration.
