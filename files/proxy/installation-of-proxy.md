### Installation of alpha-proxy (OTCv8 proxy server)

1. Upload `proxy` directory to `/home/proxy` on OTS server.
2. SSH to server, compile and install:
```
cd /home/proxy/alpha-proxy/
mkdir build
cd build
cmake ..
make -j 2
cp alpha-proxy ../../proxy_server
```
3. Make .sh files runnable:
```
cd /home/proxy
chmod +x *sh
```

### Running alpha-proxy

1. SSH to server:
```
cd /home/proxy
./start.sh
```
It will start proxies on `screen`'s.
