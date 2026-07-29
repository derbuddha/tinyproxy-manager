# Securing the Web GUI with Keycloak

`tinyproxy-gui` has no built-in authentication — anyone who can reach port `8080`
can use it, and since the container mounts `/var/run/docker.sock`, that's
effectively root-equivalent control over the host (see README's "Docker socket
access" warning). This document describes how to put the GUI behind Keycloak
using [oauth2-proxy](https://oauth2-proxy.github.io/oauth2-proxy/) as a
forward-auth gate, since the PHP app itself has no OIDC integration.

Assumes you already have a running Keycloak instance/realm elsewhere, and that
external access is routed through Nginx Proxy Manager (NPM), as in this stack.

## Architecture

```
Internet -> NPM (TLS) -> oauth2-proxy:4180 -> tinyproxy-gui:80
                              |
                              v
                          Keycloak (OIDC)
```

`tinyproxy-gui` is no longer reachable directly — only via oauth2-proxy, which
handles the login redirect, callback, and session cookie, and only forwards
requests upstream once Keycloak has authenticated the user.

## 1. Register a client in Keycloak

In the realm you want to use for this stack:

- New client, e.g. `tinyproxy-manager`
- Client type: confidential (client authentication on)
- Valid redirect URI: `https://<your-public-domain>/oauth2/callback`
- Generate a client secret (Credentials tab)

Recommended: since this app effectively grants host-level control, don't allow
just any Keycloak user in. Create a dedicated group/role, e.g.
`tinyproxy-admins`, and only add trusted accounts to it. Make sure the
client's ID token/userinfo includes a `groups` claim (add a "Group Membership"
mapper to the client if it's not already present in your realm).

## 2. Add oauth2-proxy to docker-compose.yml

```yaml
oauth2-proxy:
  image: quay.io/oauth2-proxy/oauth2-proxy:latest
  container_name: tinyproxy-oauth2-proxy
  command: --config=/etc/oauth2-proxy.cfg
  environment:
    - OAUTH2_PROXY_PROVIDER=keycloak-oidc
    - OAUTH2_PROXY_OIDC_ISSUER_URL=https://<keycloak-host>/realms/<realm>
    - OAUTH2_PROXY_CLIENT_ID=tinyproxy-manager
    - OAUTH2_PROXY_CLIENT_SECRET=<secret>          # keep out of git; use .env or a secrets file
    - OAUTH2_PROXY_COOKIE_SECRET=<openssl rand -base64 32>
    - OAUTH2_PROXY_REDIRECT_URL=https://<your-public-domain>/oauth2/callback
    - OAUTH2_PROXY_UPSTREAMS=http://tinyproxy-gui:80
    - OAUTH2_PROXY_ALLOWED_GROUPS=tinyproxy-admins
    - OAUTH2_PROXY_OIDC_GROUPS_CLAIM=groups
    - OAUTH2_PROXY_COOKIE_SECURE=true
    - OAUTH2_PROXY_COOKIE_EXPIRE=12h                # default is 168h; shorten given the blast radius
    - OAUTH2_PROXY_HTTP_ADDRESS=0.0.0.0:4180
  restart: unless-stopped
  networks:
    - codersrv_default
```

Put `OAUTH2_PROXY_CLIENT_SECRET` and `OAUTH2_PROXY_COOKIE_SECRET` in an
`.env` file (already gitignored) rather than committing them directly.

## 3. Stop publishing the app's own port

Remove the `ports: - 8080:80` mapping from the `tinyproxy-gui` service in
`docker-compose.yml`. It should only be reachable from other containers on
`codersrv_default` (i.e. from `oauth2-proxy`), never directly from the host
or internet.

## 4. Repoint NPM

In the existing NPM proxy host for this domain, change the forward target:

- Before: `tinyproxy-gui` : `80`
- After: `tinyproxy-oauth2-proxy` : `4180`

Keep the same domain and SSL certificate — only the forward target changes.
No custom Nginx config is needed in NPM; oauth2-proxy handles the entire
login/callback/session flow itself.

## 5. Verify

- Visit the public URL → should redirect to Keycloak login → after
  authenticating, land on the tinyproxy-gui dashboard.
- Confirm a Keycloak user **not** in `tinyproxy-admins` is denied access.
- Confirm the container's port is no longer reachable directly from outside
  once the `8080:80` mapping is removed (`curl` to the host IP on 8080 should
  fail/connection-refused).
- Confirm the session cookie is `Secure` and `HttpOnly`, and that sessions
  expire after `OAUTH2_PROXY_COOKIE_EXPIRE`.
