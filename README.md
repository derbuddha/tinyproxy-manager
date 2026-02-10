# Tinyproxy Manager - Docker Stack für Dockge

Ein einfacher HTTP-Proxy mit Web-GUI zum Blockieren von Domains via Regex-Patterns.

## Features

- ✅ Web-GUI zum einfachen Verwalten blockierter Domains
- ✅ Regex-Pattern-Support für flexible Domain-Filterung
- ✅ **Upstream Proxy Support** - Verkette mehrere Proxies (Proxy-Chaining)
- ✅ **NoProxy (Direkte Verbindungen)** - Umgehe den Upstream-Proxy für bestimmte Domains/IPs
- ✅ Traffic Kill-Switch zum sofortigen Stoppen aller Anfragen
- ✅ Live-Traffic Monitor mit Auto-Refresh
- ✅ Tinyproxy als leichtgewichtiger HTTP-Proxy
- ✅ Vollständig containerisiert mit Docker Compose
- ✅ Bereit für Dockge Stack-Deployment

## Ports

- **8888**: Tinyproxy HTTP-Proxy
- **8080**: Web-GUI

## Installation in Dockge

1. Entpacke das Archiv in deinen Dockge Stacks-Ordner:
   ```bash
   cd /opt/dockge/stacks
   tar -xzf tinyproxy-manager.tar.gz
   cd tinyproxy-manager
   ```

2. Starte den Stack über die Dockge Web-UI oder via CLI:
   ```bash
   docker-compose up -d
   ```

3. Öffne die Web-GUI: `http://localhost:8080`

## Verwendung

### Web-GUI
- Öffne `http://localhost:8080`
- Füge Domains zum Blocken hinzu (Regex-Patterns unterstützt)
- Nach Änderungen Tinyproxy neustarten: `docker-compose restart tinyproxy`

### Proxy nutzen

**Browser (Firefox)**:
- Einstellungen → Netzwerk → Verbindungs-Einstellungen
- Manuelle Proxy-Konfiguration: `localhost:8888`

**Terminal**:
```bash
curl -x http://localhost:8888 http://example.com
```

**Environment Variables**:
```bash
export http_proxy=http://localhost:8888
export https_proxy=http://localhost:8888
```

## Upstream Proxy (Proxy-Chaining)

Du kannst Tinyproxy so konfigurieren, dass er **alle** Anfragen an einen weiteren Proxy weiterleitet:

### Über die Web-GUI konfigurieren
1. Öffne `http://localhost:8080`
2. Scrolle zu **"Upstream Proxy (Proxy-Weiterleitung)"**
3. Aktiviere den Upstream Proxy
4. Gib Host und Port des zweiten Proxies ein
5. Klicke "Speichern" und starte Tinyproxy neu

### NoProxy - Direkte Verbindungen

Mit **NoProxy** kannst du bestimmte Domains/IPs vom Upstream-Proxy **ausnehmen**. Diese Anfragen gehen dann **direkt** ins Internet, ohne über den zweiten Proxy zu laufen.

**Beispiele für NoProxy-Einträge:**
- `localhost` - Lokale Anfragen direkt
- `192.168.0.0/16` - Privates Netzwerk direkt
- `10.0.0.0/8` - Internes Netzwerk direkt
- `.local` - Alle .local Domains direkt
- `internal.company.com` - Spezifische interne Domain

**Use Case:** 
- Upstream Proxy: `corporate-proxy.example.com:3128`
- NoProxy: `192.168.0.0/16`, `10.0.0.0/8`
- **Resultat:** Externe Anfragen gehen durch den Corporate Proxy, interne Anfragen (LAN) gehen direkt

### Manuelle Konfiguration in tinyproxy.conf
```conf
# Alle Anfragen über zweiten Proxy leiten
Upstream http proxy.example.com:3128 "."

# Ausnahmen für direkte Verbindungen
No localhost
No 192.168.0.0/16
No 10.0.0.0/8
No .local
```

## Regex-Pattern Beispiele

```
# Alle Subdomains von facebook.com blockieren
^.*facebook\.com$

# Exakt nur facebook.com (keine Subdomains)
^facebook\.com$

# Alle Domains mit "ads" im Namen
^.*\.ads\..*$

# Mehrere spezifische Domains
^.*(facebook|twitter|instagram)\.com$
```

## Konfiguration anpassen

### Tinyproxy Konfiguration
Bearbeite `tinyproxy.conf` für erweiterte Einstellungen:
- Port ändern
- Zugriffsbeschränkungen (Allow/Deny)
- Logging-Level
- Timeout-Werte

### Sicherheit für Production
In `tinyproxy.conf` die Zeile ändern:
```conf
# Statt:
Allow 0.0.0.0/0

# Besser (nur lokales Netzwerk):
Allow 192.168.0.0/16
Allow 10.0.0.0/8
```

## Troubleshooting

**Proxy funktioniert nicht:**
```bash
# Logs prüfen
docker-compose logs tinyproxy

# Container neustarten
docker-compose restart tinyproxy
```

**GUI zeigt Domains nicht an:**
```bash
# Berechtigungen prüfen
ls -la blocked-domains.txt

# Sollte lesbar sein
chmod 644 blocked-domains.txt
```

**Domain wird nicht blockiert:**
- Regex-Pattern überprüfen
- Nach Änderungen IMMER Tinyproxy neustarten
- Pattern in `blocked-domains.txt` direkt testen

## Dateistruktur

```
tinyproxy-manager/
├── docker-compose.yml       # Stack-Definition
├── tinyproxy.conf          # Proxy-Konfiguration
├── blocked-domains.txt     # Blocklist (Regex)
├── gui/
│   ├── Dockerfile          # GUI-Container Build
│   ├── index.php           # Web-Interface
│   ├── api.php             # Backend-API
│   └── style.css           # Styling
└── README.md               # Diese Datei
```

## Sicherheit & Datenschutz

- Proxy läuft komplett on-premises
- Keine externen Cloud-Dependencies
- Alle Daten bleiben im eigenen Netzwerk
- Filter-Logs in `/var/log/tinyproxy/` (im Container)

## Lizenz

MIT License - Frei verwendbar für private und kommerzielle Zwecke.
