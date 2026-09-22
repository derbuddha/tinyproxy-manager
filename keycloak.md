# Securing the Web GUI with Keycloak

`tinyproxy-gui` ships with **optional** Keycloak (OpenID Connect) login. It is
off by default: with `KEYCLOAK_ENABLED` unset the GUI behaves exactly as it
always has — anyone who can reach port `8080` can use it, and since the
container mounts `/var/run/docker.sock`, that is effectively root-equivalent
control over the host (see the README's "Docker socket access" warning).

Two ways to put Keycloak in front of it:

| | [Built-in login](#built-in-login-recommended) | [oauth2-proxy](#alternative-oauth2-proxy) |
|---|---|---|
| Extra container | no | yes |
| Configuration | env vars on the existing service | a second service + NPM change |
| Shows who is signed in | yes, in the GUI header | no |
| Per-user sessions in the app | yes | no (proxy-level only) |

Both assume you already have a running Keycloak instance/realm elsewhere, and
that external access is routed through Nginx Proxy Manager (NPM), as in this
stack.

---

## Built-in login (recommended)

```
Internet -> NPM (TLS) -> tinyproxy-gui:80
                              |
                              v
                          Keycloak (OIDC)
```

The GUI performs the Authorization Code flow with PKCE itself. Every page and
every API endpoint is gated: `index.php`, `history.php`, `export.php` and
`api.php` all refuse to answer without a valid session. An unauthenticated page
request gets a login dialog whose only control is a **Sign in with Keycloak**
button; the redirect to Keycloak happens on that click (`login.php`), never on
merely opening a page.

### 1. Register a client in Keycloak

In the realm you want to use for this stack:

- **Clients → Create client**, client ID e.g. `tinyproxy-manager`
- **Client authentication: On** (a confidential client — the GUI keeps a secret)
- **Standard flow** enabled; direct access grants can be off
- **Valid redirect URI**: `https://<your-gui-domain>/auth-callback.php`
- **Valid post logout redirect URI**: `https://<your-gui-domain>/`
- Copy the secret from the **Credentials** tab

Since this app effectively grants host-level control, don't let just any realm
user in. Create a dedicated group, e.g. `tinyproxy-admins`, add only trusted
accounts, and make sure the client's ID token carries a `groups` claim:

- **Client scopes → `tinyproxy-manager-dedicated` → Add mapper → By configuration
  → Group Membership**
- Token Claim Name `groups`, **Add to ID token: On**
- "Full group path" may be on or off — both `/tinyproxy-admins` and
  `tinyproxy-admins` are accepted in `KEYCLOAK_ALLOWED_GROUPS`

### 2. Configure the stack

Copy `.env.example` to `.env` next to `docker-compose.yml` and fill in:

```ini
KEYCLOAK_ENABLED=true
KEYCLOAK_ISSUER=https://keycloak.example.com/realms/myrealm
KEYCLOAK_CLIENT_ID=tinyproxy-manager
KEYCLOAK_CLIENT_SECRET=<from the Credentials tab>
KEYCLOAK_ALLOWED_GROUPS=tinyproxy-admins
```

`.env` is gitignored — the client secret must not be committed. The
`docker-compose.yml` service already passes these through, so:

```bash
docker compose up -d --build tinyproxy-gui
```

### 3. Stop publishing the app's own port

With login enabled the GUI is still directly reachable on `8080` — but that
port bypasses nothing (the login is inside the app), so it is protected too.
It is still worth removing the `ports: - 8080:80` mapping so the only path in
is the TLS-terminated one:

```yaml
  tinyproxy-gui:
    # ports:
    #   - 8080:80
```

**Session cookies are only marked `Secure` when the request arrives over
HTTPS**, which the GUI detects from `X-Forwarded-Proto`. NPM sets that header
by default. Reaching the GUI over plain HTTP still works, but the session
cookie then travels unencrypted — so use the HTTPS host for real use.

### All configuration options

| Variable | Default | Meaning |
|---|---|---|
| `KEYCLOAK_ENABLED` | `false` | `true`/`1`/`yes`/`on` turns login on |
| `KEYCLOAK_ISSUER` | – | Realm URL, e.g. `https://kc.example.com/realms/myrealm` |
| `KEYCLOAK_CLIENT_ID` | – | Confidential client id |
| `KEYCLOAK_CLIENT_SECRET` | – | Its secret |
| `KEYCLOAK_REDIRECT_URI` | derived from the request | Override when `X-Forwarded-Proto`/`-Host` aren't set |
| `KEYCLOAK_SCOPES` | `openid profile email` | Requested scopes |
| `KEYCLOAK_ALLOWED_GROUPS` | *(empty)* | Comma-separated; empty = any realm user |
| `KEYCLOAK_GROUPS_CLAIM` | `groups` | Claim holding group names |
| `KEYCLOAK_ALLOWED_ROLES` | *(empty)* | Comma-separated realm or client roles |
| `KEYCLOAK_SESSION_TTL` | `43200` | GUI session lifetime in seconds (12h) |
| `KEYCLOAK_POST_LOGOUT_REDIRECT` | the GUI's own URL | Where Keycloak returns after sign-out |
| `KEYCLOAK_CA_BUNDLE` | *(empty)* | CA file, for a Keycloak behind a private CA |
| `KEYCLOAK_INSECURE_SKIP_TLS_VERIFY` | `false` | Skips TLS verification — testing only |

A user is let in when they match **either** an allowed group **or** an allowed
role. With both lists empty, everyone the realm authenticates gets full control
of the proxy and the Docker socket.

### What it does on each request

- No session → redirect to Keycloak, and back to the page originally requested
- `api.php` answers `401 {"auth_required":true}` instead of redirecting, so an
  expired session surfaces as a clean re-login rather than a broken API call
- The ID token is verified locally: RS256/384/512 signature against the realm
  JWKS, plus `iss`, `aud`/`azp`, `exp`, `nbf` and the login's `nonce`
- Session cookie `TPM_SESSION`: `HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS.
  `SameSite=Lax` is what keeps cross-site POSTs off the `api.php` write
  endpoints
- Signing out also ends the Keycloak SSO session (RP-initiated logout with
  `id_token_hint`), so the next visit doesn't log straight back in
- **Fail closed**: if `KEYCLOAK_ENABLED` is on but the configuration is
  incomplete, or Keycloak can't be reached, the GUI serves an error page — it
  never falls back to unauthenticated access

### Verify

- Visit the GUI → login dialog with a single **Sign in with Keycloak** button →
  after login, the dashboard shows a `👤 <username>` chip with a **Sign out** link
- A user **not** in `tinyproxy-admins` gets "Access denied" and no dashboard
- `curl -X POST https://<gui-domain>/api.php` without a cookie returns HTTP 401
- **Sign out** → back at Keycloak's login, not straight into the GUI
- Break the config on purpose (wrong secret) → error page, not an open GUI

### Troubleshooting

| Symptom | Cause |
|---|---|
| "Invalid parameter: redirect_uri" at Keycloak | The redirect URI isn't registered on the client. Use the exact value shown on the GUI's error page. |
| Redirect loops back to login | Cookie dropped — usually the GUI was reached over HTTP while `X-Forwarded-Proto: https` was set. Use the HTTPS URL. |
| "Token exchange failed" | Wrong client id/secret, or the client isn't confidential. |
| "Access denied" for a group member | The `groups` claim isn't in the ID token. Add the Group Membership mapper (step 1). |
| "Keycloak is unreachable" | The GUI container can't resolve/reach the issuer, or a private CA is in play → `KEYCLOAK_CA_BUNDLE`. |

Auth events are written to the container log: `docker logs tinyproxy-gui`.

---

## Alternative: oauth2-proxy

Useful if you'd rather keep authentication out of the app entirely, or already
run [oauth2-proxy](https://oauth2-proxy.github.io/oauth2-proxy/) for other
services. Leave `KEYCLOAK_ENABLED` off when using this.

```
Internet -> NPM (TLS) -> oauth2-proxy:4180 -> tinyproxy-gui:80
                              |
                              v
                          Keycloak (OIDC)
```

Register the client as above, but with redirect URI
`https://<your-public-domain>/oauth2/callback`, then add:

```yaml
oauth2-proxy:
  image: quay.io/oauth2-proxy/oauth2-proxy:latest
  container_name: tinyproxy-oauth2-proxy
  environment:
    - OAUTH2_PROXY_PROVIDER=keycloak-oidc
    - OAUTH2_PROXY_OIDC_ISSUER_URL=https://<keycloak-host>/realms/<realm>
    - OAUTH2_PROXY_CLIENT_ID=tinyproxy-manager
    - OAUTH2_PROXY_CLIENT_SECRET=${KEYCLOAK_CLIENT_SECRET}
    - OAUTH2_PROXY_COOKIE_SECRET=<openssl rand -base64 32>
    - OAUTH2_PROXY_REDIRECT_URL=https://<your-public-domain>/oauth2/callback
    - OAUTH2_PROXY_UPSTREAMS=http://tinyproxy-gui:80
    - OAUTH2_PROXY_ALLOWED_GROUPS=tinyproxy-admins
    - OAUTH2_PROXY_OIDC_GROUPS_CLAIM=groups
    - OAUTH2_PROXY_COOKIE_SECURE=true
    - OAUTH2_PROXY_COOKIE_EXPIRE=12h    # default is 168h; shorten given the blast radius
    - OAUTH2_PROXY_HTTP_ADDRESS=0.0.0.0:4180
  restart: unless-stopped
  networks:
    - codersrv_default
```

Then:

1. Remove the `ports: - 8080:80` mapping from `tinyproxy-gui` — with this setup
   that port *is* an unauthenticated bypass, so it must not be published.
2. In NPM, change the forward target from `tinyproxy-gui:80` to
   `tinyproxy-oauth2-proxy:4180`. Same domain, same certificate.
3. Verify as above, and confirm the host port is no longer reachable
   (`curl http://<host-ip>:8080` should be refused).
