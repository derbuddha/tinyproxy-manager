# Tinyproxy Manager - CDE - Cloud Development Environment

An HTTP proxy with a web GUI for domain filtering and per-container access control, built around Tinyproxy.

## Features

- ✅ Web GUI for easy management of blocked/allowed domains
- ✅ Regex pattern support for flexible domain filtering
- ✅ **Domain Filter Mode** - switch the domain filter between a blocklist (block only listed domains) and an allowlist (block everything except listed domains)
- ✅ **Container Access Control** - Allow or block individual Docker containers by name/IP, with a dual-mode policy (Block-new vs. Allow-new) for how unrecognized containers are treated
- ✅ **Upstream Proxy Support** - Chain multiple proxies (Proxy Chaining)
- ✅ **Upstream Domains (Opt-In Routing)** - Only explicitly listed domains/IPs are forwarded to the upstream proxy; everything else goes direct
- ✅ Traffic kill-switch for immediately stopping all requests
- ✅ Live traffic monitor with auto-refresh and client-side domain filtering
- ✅ Persistent traffic history (up to 10,000 entries, oldest dropped first) with a paginated full-log viewer and JSON export
- ✅ Tinyproxy as lightweight HTTP proxy
- ✅ Fully containerized with Docker Compose
- ✅ Ready for Dockge stack deployment

## Ports

- **8888**: Tinyproxy HTTP Proxy
- **8080**: Web GUI

## Prerequisites

- An existing Docker network that your other containers share (the compose file references it as an **external** network, `codersrv_default` by default). Adjust the network name in `docker-compose.yml` and in `gui/api.php` (`getNetworkContainers()`) to match your own setup if different.
- Docker socket access for the GUI container (`/var/run/docker.sock`) — required for container discovery, auto-restarting Tinyproxy, and the access control features. This effectively grants the GUI container root-equivalent control over the Docker host, so only expose port 8080 on a trusted network.

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

## Container Access Control

The GUI discovers every container on the shared Docker network and lets you allow or deny each one individually. Access is enforced with `Allow`/`Deny` IP rules written into `tinyproxy.conf`, so it works independently of the domain blocklist.

### Policy modes (`new_client_policy`)

The active mode is stored as `new_client_policy` in `proxy-config.json` (`"block"` or `"allow"`):

- **Block new** (`new_client_policy: "block"`, default): only containers listed in `allowed-containers.txt` may use the proxy. Everything else is denied. Every new workspace/container has to be explicitly allowed here before it can reach the proxy at all.
- **Allow new** (`new_client_policy: "allow"`): every container may use the proxy except those explicitly listed in `blocked-containers.txt`. Use this if you don't want to maintain a per-container whitelist and would rather gate access purely by [Domain Filter Mode](#domain-filter-mode) below - see "Combining with Domain Filter Mode" for why that pairing matters.

Switch modes with the **New clients: Block new / Allow new** toggle in the "Container Access Control" card. Changing the policy re-syncs the `Allow`/`Deny` rules and restarts Tinyproxy.

### Managing containers

- Click **Allow**/**Block** next to a container to add or remove it from the relevant list.
- Containers that were explicitly blocked are still shown (marked `offline`) even if they're not currently running, so a rule isn't lost just because the container is stopped.
- Container IPs can change on restart (e.g. after `docker compose up`). Use **⚡ Sync IPs & Restart** to refresh the `Allow`/`Deny` rules with each container's current IP and restart Tinyproxy.
- `tinyproxy` and `tinyproxy-gui` are excluded from the list since they aren't proxy clients.

### Combining with Domain Filter Mode

Container Access Control and [Domain Filter Mode](#domain-filter-mode) are two **independent** gates - a request has to pass both. This trips people up, so to be explicit:

- **Block new** (container whitelist) + any domain mode: only whitelisted containers get through at all; what they can then reach depends on the domain mode separately.
- **Allow new** (every container passes) + **Allow only listed domains**: this is the "any workspace, curated destinations" model - you don't maintain a per-container whitelist at all, and `allowed-domains.txt` alone controls what's reachable, uniformly for every container. This is usually what you want if containers/workspaces come and go frequently (e.g. ephemeral Coder workspaces) and you don't want to whitelist each one by name.
- **Allow new** + **Block listed domains**: effectively open access - every container can reach everything except whatever's explicitly blocked. Rarely what you want unless you're only using the domain blocklist for a handful of known-bad domains.

⚠️ If you switch to **Allow only listed domains** while `allowed-domains.txt` is empty (or missing an entry your workspaces need), that domain - and every other unlisted one - is blocked for **every** container, not just new ones. Populate `allowed-domains.txt` with everything your workspaces actually need *before* flipping Domain Filter Mode to "allow", to avoid an unexpected full outage.

## Domain Filter Mode

The domain filter can work in one of two modes, toggled in the "Domain Filter Mode" card:

- **Block listed domains** (default): domains in `blocked-domains.txt` are denied; everything else is reachable.
- **Allow only listed domains**: domains in `allowed-domains.txt` are the *only* ones reachable; everything else is blocked.

Only one list is enforced by Tinyproxy at a time (it maps to Tinyproxy's native `FilterDefaultDeny` option) - the inactive card is shown dimmed in the GUI. Switching modes re-points Tinyproxy's `Filter` directive and restarts it.

### Comments on Allowed Domains

Every entry in the Allowed Domains list can carry a free-text comment explaining *why* it's there - handy once the list grows past a handful of entries and "what needed `vscode.download.prss.microsoft.com` again?" becomes a real question.

- Add one right away via the optional comment field next to the domain in the **Add Domain** form.
- Add or change one later with the **💬 Comment** button on any entry; press Enter to save, Escape to cancel.
- Saving an empty comment removes it.

Comments are stored inline in `allowed-domains.txt`, after a `#` on the same line:

```
github.com                            # needed for git clone
^.*\.fritz\.box$                      # local network
vscode.download.prss.microsoft.com    # VS Code updates
```

Tinyproxy's filter parser cuts every line at the first whitespace or unescaped `#`, so the comment never reaches the filter engine - it's documentation only, and a domain mentioned inside a comment is **not** allowed by it. Because of that, editing a comment needs no Tinyproxy restart and the GUI doesn't trigger one (adding or deleting a domain still does). For the same reason a domain pattern itself can't contain a space or a bare `#`; the GUI rejects those with an explanatory message.

## Traffic Kill-Switch

The **Stop All Traffic** button in the GUI immediately blocks every request through the proxy, regardless of the domain filter mode or container access rules — useful for quickly cutting off all outbound traffic in an emergency. It works by overriding the container-access `Allow`/`Deny` rules to `Deny 0.0.0.0/0` (rather than touching the domain filter, so it can't be undermined by whatever domain filter mode happens to be active) and restarts Tinyproxy. Disabling it restores the normal per-container rules. The button and card change appearance while traffic is stopped so the state is hard to miss.

## Live Traffic Monitor

The GUI polls the Tinyproxy log (`/var/log/tinyproxy/tinyproxy.log`) every 5 seconds and shows the most recent requests, including source container IP, method, and target domain, with allowed, blocked (domain filter), and unauthorized (access control) requests visually distinguished.

- **Local Domain Filter**: hide noisy or uninteresting domains from the monitor view. Filters are stored in the browser's `localStorage`, so they're per-browser/device and don't affect what Tinyproxy actually allows. See also the server-wide **Noise Filter** below.
- Auto-refresh can be toggled off if you want to inspect the current list without it updating.

### Traffic History, Full Log Page & JSON Export

Every parsed traffic entry is also archived into `traffic-history.json`, capped at **10,000 entries** (oldest dropped first as new ones come in). Archiving happens two ways so it doesn't depend on the GUI being open:

- A background logger (`gui/traffic-logger.php`, started by `entrypoint.sh`) polls the Tinyproxy log every 10 seconds.
- Each time the dashboard's Live Traffic Monitor polls the API, it also triggers an ingest as a redundant trigger.

Two dedicated pages built on top of that archive:

- **📜 Full Log** (`history.php`) - a paginated view (100 entries/page) of the entire stored history, newest first. Linked from the Live Traffic Monitor card.
- **⬇ Export JSON** (`export.php`) - downloads the full stored history as a `.json` file.

### Noise Filter (server-wide, display-only)

Some traffic is just noise - e.g. a self-hosted service phoning its own health-check endpoint every few seconds - and clutters both the Live Monitor and the Full Log page. The **🔇 Noise Filter** panel (below the Live Monitor's local domain filter) lets you suppress matching entries for *every* viewer, backed by `traffic-noise-filters.txt`.

- Matches case-insensitively against both the request domain and the source IP/name (substring match) - e.g. `coder.example.com` or `172.19.0.1`.
- Display-only: `traffic-history.json` still archives every entry untouched, so the stored count and JSON export stay complete - only what's *shown* on the Live Monitor and Full Log page is affected. Both pages show a "N hidden by noise filter" count so suppressed traffic is never silently invisible.
- This is different from the **🔍 Local Domain Filter** also on the Live Monitor card: that one is per-browser (`localStorage`), doesn't touch the Full Log page, and is meant for quick one-off decluttering rather than a permanent, shared suppression.

## Upstream Proxy (Proxy Chaining)

Tinyproxy can forward requests to a second proxy. Forwarding is **opt-in per domain**:
by default every request - including every allowed domain - connects **directly** to
the internet, and only the domains you list explicitly are sent to the upstream proxy.

### Configure via Web GUI
1. Open `http://localhost:8080`
2. Scroll to **"Upstream Proxy (Proxy Forwarding)"**
3. Enable the upstream proxy
4. Enter host and port of the second proxy
5. Click "Save Upstream Proxy"
6. Add the domains/IPs that should go through it under **"Upstream Domains"**

Until at least one domain is listed, enabling the upstream proxy changes nothing -
all traffic keeps going direct.

### Upstream Domains - Routed Connections

**Examples for Upstream Domains entries:**
- `internal.example.com` - that host (and its subdomains) via the upstream proxy
- `.corp.local` - all `.corp.local` domains via the upstream proxy
- `10.0.0.0/8` - the whole internal network via the upstream proxy

**Use Case:**
- Upstream Proxy: `corporate-proxy.example.com:3128`
- Upstream Domains: `.corp.local`, `10.0.0.0/8`
- **Result:** Corporate/internal requests go through the corporate proxy, everything
  else goes straight out

### Manual Configuration in tinyproxy.conf
```conf
# Only these hosts are forwarded to the second proxy
upstream proxy.example.com:3128 "test.de"
upstream proxy.example.com:3128 ".corp.local"
upstream proxy.example.com:3128 "10.0.0.0/8"

# Anything not matched above connects directly - no extra rule needed
```

With the rules above, a request to `test.de` (or `www.test.de`) is handed to
`proxy.example.com:3128`, while a request to any other allowed domain -
`github.com`, say - goes straight out.

> **Note:** Tinyproxy's catch-all form (`Upstream proxy.example.com:3128` without a
> domain, combined with `no upstream "..."` exceptions) routes *everything* through
> the upstream proxy. The GUI no longer writes it; a config still containing it is
> migrated to the opt-in form (host and port are kept, the domain list starts empty)
> the next time the upstream settings are saved.

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

After making changes to GUI files (`index.php`, `api.php`, `traffic-parser.php`, `traffic-logger.php`, `history.php`, `export.php`, `style.css`, `entrypoint.sh`, or `Dockerfile`), rebuild and restart the container:

```bash
docker compose up -d --build tinyproxy-gui 2>&1
```

This rebuilds the image from the updated source files and recreates the container in one step. The `tinyproxy` proxy container is not affected and keeps running.

## File Structure

```
tinyproxy-manager/
├── docker-compose.yml          # Stack definition
├── tinyproxy.conf              # Proxy configuration
├── blocked-domains.txt         # Domain blocklist (regex, used in "Block listed" mode)
├── allowed-domains.txt         # Domain allowlist (regex + optional "# comment", used in "Allow only listed" mode)
├── allowed-containers.txt      # Containers allowed in Block-new mode
├── blocked-containers.txt      # Containers denied in Allow-new mode
├── proxy-config.json           # GUI settings (new client policy, domain filter mode, kill-switch state)
├── traffic-history.json        # Persisted traffic log (capped at 10,000 entries)
├── traffic-noise-filters.txt   # Server-wide display filters for the Live Monitor / Full Log page
├── gui/
│   ├── Dockerfile              # GUI container build
│   ├── entrypoint.sh           # Container startup, permissions & background logger
│   ├── index.php               # Web interface (dashboard)
│   ├── api.php                 # Backend API
│   ├── traffic-parser.php      # Shared tinyproxy.log parsing + history ingestion
│   ├── domain-filter.php       # Shared filter-list parsing (domain + inline comment)
│   ├── traffic-logger.php      # Background daemon that archives log entries
│   ├── history.php             # Paginated full traffic log page
│   ├── export.php              # JSON export/download of the traffic history
│   └── style.css               # Styling
└── README.md                   # This file
```

## Security & Privacy

- Proxy runs completely on-premises
- No external cloud dependencies
- All data stays in your own network
- Filter logs in `/var/log/tinyproxy/` (in container)
- The GUI container mounts `/var/run/docker.sock` to discover containers and restart Tinyproxy. This gives it root-equivalent access to the Docker host — only run it on a trusted network and don't expose port 8080 publicly without additional authentication (e.g. a reverse proxy with basic auth or SSO)

## License

MIT License - Free to use for private and commercial purposes.
