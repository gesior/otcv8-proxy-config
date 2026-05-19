#!/bin/bash

if [ $# -eq 0 ]; then
    echo "Parameter required! Value: ots, www, otswww or proxy"
    exit
fi

if [ $1 != "ots" ] && [ $1 != "www" ] && [ $1 != "otswww" ] && [ $1 != "proxy" ]; then
    echo "Invalid parameter issue! Value: ots, www, otswww or proxy"
    exit
fi

# ALL: clearing iptables
iptables -F
iptables -P INPUT ACCEPT
iptables -P FORWARD ACCEPT
iptables -P OUTPUT ACCEPT

# ALL: not banning local connections
iptables -A INPUT -s 127.0.0.1/8 -j ACCEPT

# ALL: not banning own servers
ips_with_unlimited_access

if [ $1 = "www" ]; then
  # WWW: access to www only from cloudflare
  for i in `curl https://www.cloudflare.com/ips-v4`; do iptables -I INPUT -p tcp -m multiport --dports http,https -s $i -j ACCEPT; done
  for i in `curl https://www.cloudflare.com/ips-v6`; do ip6tables -I INPUT -p tcp -m multiport --dports http,https -s $i -j ACCEPT; done
  iptables -A INPUT -p tcp -m multiport --dports http,https -j DROP
  ip6tables -A INPUT -p tcp -m multiport --dports http,https -j DROP
fi

if [ $1 = "ots" ]; then
  # OTS: blocking access to mysql
  iptables -A INPUT -p tcp -m multiport --dports database_port -j DROP
  # OTS: access to proxy only from proxy and www server IPs
  iptables -A INPUT -p tcp -m multiport --dports proxy_start_port:proxy_end_port -j DROP
  # OTS: access to ots only from proxy and www server IPs
  iptables -A INPUT -p tcp -m multiport --dports ots_ports -j DROP
fi

if [ $1 = "otswww" ]; then
  # OTS: blocking access to mysql
  iptables -A INPUT -p tcp -m multiport --dports database_port -j DROP
  # OTS: access to proxy only from proxy and www server IPs
  iptables -A INPUT -p tcp -m multiport --dports proxy_start_port:proxy_end_port -j DROP
  # OTS: access to ots only from proxy and www server IPs
  iptables -A INPUT -p tcp -m multiport --dports ots_ports -j DROP

  # WWW: access to www only from cloudflare
  for i in `curl https://www.cloudflare.com/ips-v4`; do iptables -I INPUT -p tcp -m multiport --dports http,https -s $i -j ACCEPT; done
  for i in `curl https://www.cloudflare.com/ips-v6`; do ip6tables -I INPUT -p tcp -m multiport --dports http,https -s $i -j ACCEPT; done
  iptables -A INPUT -p tcp -m multiport --dports http,https -j DROP
  ip6tables -A INPUT -p tcp -m multiport --dports http,https -j DROP
fi

if [ $1 = "proxy" ]; then
  # PROXY: limits for external connections to proxy ports proxy_start_port:proxy_end_port
  # 10 connections per 1 IP
  iptables -A INPUT -p tcp --syn --dport proxy_start_port:proxy_end_port -m connlimit --connlimit-above 10 --connlimit-mask 32 -j REJECT --reject-with tcp-reset
  # 40 new connections per minute per 1 IP
  iptables -A INPUT -p tcp --dport proxy_start_port:proxy_end_port -m state --state NEW -m hashlimit --hashlimit-mode srcip --hashlimit-above 40/min --hashlimit-burst 41 --hashlimit-name conn_proxy_rate_min -j REJECT --reject-with tcp-reset
  # 5 new connections per second per 1 IP
  iptables -A INPUT -p tcp --dport proxy_start_port:proxy_end_port -m state --state NEW -m hashlimit --hashlimit-mode srcip --hashlimit-above 5/sec --hashlimit-burst 6 --hashlimit-name conn_proxy_rate_sec -j REJECT --reject-with tcp-reset
  # 450 incoming packets per second per 1 IP (449 and 450 are intentional, because iptables has some issue when both values are the same)
  iptables -A INPUT -p tcp --dport proxy_start_port:proxy_end_port -m hashlimit --hashlimit-mode srcip --hashlimit-above 449/sec --hashlimit-burst 450 --hashlimit-name conn_proxy_rate_packets_sec -j REJECT --reject-with tcp-reset
  # 10 kb incoming transfer per second per 1 IP
  iptables -A INPUT -p tcp --dport proxy_start_port:proxy_end_port -m hashlimit --hashlimit-above 10kb/s --hashlimit-mode srcip --hashlimit-name bandwidth_proxy_sec -j REJECT --reject-with tcp-reset

  # PROXY: limits for external connections to OTS ports ots_status_port
  # 3 connections per 1 IP
  iptables -A INPUT -p tcp --syn --dport ots_status_port -m connlimit --connlimit-above 3 --connlimit-mask 32 -j REJECT --reject-with tcp-reset
  # 5 new connections per minute per 1 IP
  iptables -A INPUT -p tcp --dport ots_status_port -m state --state NEW -m hashlimit --hashlimit-mode srcip --hashlimit-above 5/min --hashlimit-burst 6 --hashlimit-name conn_ots_rate_min -j REJECT --reject-with tcp-reset
  # 2 new connections per second per 1 IP
  iptables -A INPUT -p tcp --dport ots_status_port -m state --state NEW -m hashlimit --hashlimit-mode srcip --hashlimit-above 2/sec --hashlimit-burst 3 --hashlimit-name conn_ots_rate_sec -j REJECT --reject-with tcp-reset
  # 150 incoming packets per second per 1 IP (149 and 150 are intentional, because iptables has some issue when both values are the same)
  iptables -A INPUT -p tcp --dport ots_status_port -m hashlimit --hashlimit-mode srcip --hashlimit-above 149/sec --hashlimit-burst 150 --hashlimit-name conn_ots_rate_packets_sec -j REJECT --reject-with tcp-reset
  # 10 kb incoming transfer per second per 1 IP
  iptables -A INPUT -p tcp --dport ots_status_port -m hashlimit --hashlimit-above 10kb/s --hashlimit-mode srcip --hashlimit-name bandwidth_ots_sec -j REJECT --reject-with tcp-reset
fi

# ALL: restart fail2ban service
systemctl restart fail2ban
