#!/usr/bin/env bash
# Sanitizes personal/environment-specific data from the working tree before
# committing/pushing to a public GitHub repo. Does NOT touch git history.
#
# What it does:
#   - Resets container allow/block lists to empty templates
#   - Resets allowed-domains.txt to its template comments
#   - Resets the noise-filter list to its template comments
#   - Clears tinyproxy.conf's Allow list back to just localhost
#   - Deletes traffic-history.json (real captured traffic log)
#
# Run from the repo root: ./refresh.sh

set -euo pipefail
cd "$(dirname "$0")"

echo "Resetting allowed-containers.txt ..."
cat > allowed-containers.txt <<'EOF'
# Allowed containers for tinyproxy access
# Managed by the Tinyproxy GUI
EOF

echo "Resetting blocked-containers.txt ..."
cat > blocked-containers.txt <<'EOF'
# Blocked containers (explicit denies in Allow-new mode)
# Managed by the Tinyproxy GUI
EOF

echo "Resetting allowed-domains.txt ..."
cat > allowed-domains.txt <<'EOF'
# Allowed Domains - one per line
# Regex patterns supported
# Only enforced when Domain Filter Mode is set to "Allow only listed domains" -
# in that mode every domain NOT listed here is blocked.
# An entry may carry a trailing comment after "#" - Tinyproxy ignores it.
# Examples:
# ^.*\.github\.com$    # source code
# ^.*\.docker\.com$
EOF

echo "Resetting traffic-noise-filters.txt ..."
cat > traffic-noise-filters.txt <<'EOF'
# Noise Filters - one domain/IP substring per line
# Managed by the Tinyproxy GUI
# Hides matching entries from the Live Monitor and Full Log page only (display-only).
# The underlying traffic-history.json still keeps every entry, so exports stay complete.
# Matches case-insensitively against both the request domain and the source IP/name.
EOF

echo "Removing traffic-history.json (contains real captured traffic) ..."
rm -f traffic-history.json

echo "Clearing container Allow rules in tinyproxy.conf ..."
awk '
  /# CONTAINER_ALLOW_START/ { print; print "Allow 127.0.0.1"; skip=1; next }
  /# CONTAINER_ALLOW_END/   { skip=0 }
  skip { next }
  { print }
' tinyproxy.conf > tinyproxy.conf.tmp && mv tinyproxy.conf.tmp tinyproxy.conf

echo "Done. Review the changes with 'git diff' before committing."
