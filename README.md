# Tinyproxy Manager - Docker Stack for Dockge

A simple HTTP proxy with web GUI for blocking domains via regex patterns.

## Features

- ✅ Web GUI for easy management of blocked domains
- ✅ Regex pattern support for flexible domain filtering
- ✅ **Upstream Proxy Support** - Chain multiple proxies (Proxy Chaining)
- ✅ **NoProxy (Direct Connections)** - Bypass the upstream proxy for specific domains/IPs
- ✅ Traffic kill-switch for immediately stopping all requests
- ✅ Live traffic monitor with auto-refresh
- ✅ Tinyproxy as lightweight HTTP proxy
- ✅ Fully containerized with Docker Compose
- ✅ Ready for Dockge stack deployment

## Ports

- **8888**: Tinyproxy HTTP Proxy
- **8080**: Web GUI

## Installation in Dockge

1. Extract the archive to your Dockge stacks folder:
   ```bash
   cd /opt/dockge/stacks
   tar -xzf tinyproxy-manager.tar.gz
   cd tinyproxy-manager
   ```

2. Start the stack via Dockge Web UI or CLI:
   ```bash
   docker-compose up -d
   ```

3. Open the Web GUI: `http://localhost:8080`

## Usage

### Web GUI
- Open `http://localhost:8080`
- Add domains to block (regex patterns supported)
- After changes, restart Tinyproxy: `docker-compose restart tinyproxy`

### Using the Proxy

**Browser (Firefox)**:
- Settings → Network → Connection Settings
- Manual proxy configuration: `localhost:8888`

**Terminal**:
```bash
curl -x http://localhost:8888 http://example.com
```

**Environment Variables**:
```bash
export http_proxy=http://localhost:8888
export https_proxy=http://localhost:8888
```

## Upstream Proxy (Proxy Chaining)

You can configure Tinyproxy to forward **all** requests to another proxy:

### Configure via Web GUI
1. Open `http://localhost:8080`
2. Scroll to **"Upstream Proxy (Proxy Forwarding)"**
3. Enable the upstream proxy
4. Enter host and port of the second proxy
5. Click "Save" and restart Tinyproxy

### NoProxy - Direct Connections

With **NoProxy** you can exclude specific domains/IPs from the upstream proxy. These requests will go **directly** to the internet without passing through the second proxy.

**Examples for NoProxy entries:**
- `localhost` - Local requests direct
- `192.168.0.0/16` - Private network direct
- `10.0.0.0/8` - Internal network direct
- `.local` - All .local domains direct
- `internal.company.com` - Specific internal domain

**Use Case:** 
- Upstream Proxy: `corporate-proxy.example.com:3128`
- NoProxy: `192.168.0.0/16`, `10.0.0.0/8`
- **Result:** External requests go through the corporate proxy, internal requests (LAN) go direct

### Manual Configuration in tinyproxy.conf
```conf
# Forward all requests through second proxy
Upstream http proxy.example.com:3128 "."

# Exceptions for direct connections
No localhost
No 192.168.0.0/16
No 10.0.0.0/8
No .local
```

## Regex Pattern Examples

```
# Block all subdomains of facebook.com
^.*facebook\.com$

# Exact match only facebook.com (no subdomains)
^facebook\.com$

# All domains with "ads" in the name
^.*\.ads\..*$

# Multiple specific domains
^.*(facebook|twitter|instagram)\.com$
```

## Customizing Configuration

### Tinyproxy Configuration
Edit `tinyproxy.conf` for advanced settings:
- Change port
- Access restrictions (Allow/Deny)
- Logging level
- Timeout values

### Security for Production
Change the line in `tinyproxy.conf`:
```conf
# Instead of:
Allow 0.0.0.0/0

# Better (local network only):
Allow 192.168.0.0/16
Allow 10.0.0.0/8
```

## Troubleshooting

**Proxy not working:**
```bash
# Check logs
docker-compose logs tinyproxy

# Restart container
docker-compose restart tinyproxy
```

**GUI doesn't show domains:**
```bash
# Check permissions
ls -la blocked-domains.txt

# Should be readable
chmod 644 blocked-domains.txt
```

**Domain not being blocked:**
- Check regex pattern
- ALWAYS restart Tinyproxy after changes
- Test pattern in `blocked-domains.txt` directly

## Updating the GUI Container

After making changes to GUI files (`index.php`, `api.php`, `style.css`, `entrypoint.sh`, or `Dockerfile`), rebuild and restart the container:

```bash
docker compose up -d --build tinyproxy-gui 2>&1
```

This rebuilds the image from the updated source files and recreates the container in one step. The `tinyproxy` proxy container is not affected and keeps running.

## File Structure

```
tinyproxy-manager/
├── docker-compose.yml          # Stack definition
├── tinyproxy.conf              # Proxy configuration
├── blocked-domains.txt         # Domain blocklist (regex)
├── allowed-containers.txt      # Containers allowed in Block-new mode
├── blocked-containers.txt      # Containers denied in Allow-new mode
├── proxy-config.json           # GUI settings (new client policy)
├── gui/
│   ├── Dockerfile              # GUI container build
│   ├── entrypoint.sh           # Container startup & permissions
│   ├── index.php               # Web interface
│   ├── api.php                 # Backend API
│   └── style.css               # Styling
└── README.md                   # This file
```

## Security & Privacy

- Proxy runs completely on-premises
- No external cloud dependencies
- All data stays in your own network
- Filter logs in `/var/log/tinyproxy/` (in container)

## License

MIT License - Free to use for private and commercial purposes.
