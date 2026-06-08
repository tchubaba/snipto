#!/bin/bash
# crowdsec-setup.sh - One-time CrowdSec agent registration and API key generation
set -euo pipefail

echo "Setting up CrowdSec..."
echo "Checking if CrowdSec credentials file exists..."

if docker exec snipto-crowdsec-1 test -f /etc/crowdsec/local_api_credentials.yaml 2>/dev/null; then
    echo "CrowdSec agent is already registered (credentials file found)."
else
    echo "Registering CrowdSec agent..."
    PASSWORD=$(openssl rand -hex 16)
    docker exec snipto-crowdsec-1 cscli machines add snipto --password "$PASSWORD"
fi

echo ""
echo "Generating API key for OpenResty bouncer..."

# Check if CROWDSEC_API_KEY is already set in environment or .env
if [ -n "${CROWDSEC_API_KEY:-}" ]; then
    API_KEY="${CROWDSEC_API_KEY}"
elif grep -q '^CROWDSEC_API_KEY=.' .env 2>/dev/null; then
    API_KEY=$(grep '^CROWDSEC_API_KEY=' .env | cut -d= -f2)
else
    API_KEY=""
fi

if [ -n "$API_KEY" ]; then
    echo "CROWDSEC_API_KEY is already set."
else
    # Try to create the bouncer.
    CSCLI_OUTPUT=$(docker exec snipto-crowdsec-1 cscli bouncers add snipto 2>&1 || true)
    echo "$CSCLI_OUTPUT"
    
    # If creation failed because bouncer already exists, delete and recreate it.
    if echo "$CSCLI_OUTPUT" | grep -q "already exists"; then
        echo "Bouncer 'snipto' already exists. Deleting and recreating to generate a new key..."
        docker exec snipto-crowdsec-1 cscli bouncers remove snipto 2>&1 || true
        CSCLI_OUTPUT=$(docker exec snipto-crowdsec-1 cscli bouncers add snipto 2>&1)
        echo "$CSCLI_OUTPUT"
    fi
    
    # Extract the API key from cscli output.
    # The key appears on the second line after "API key for 'snipto':"
    API_KEY=$(echo "$CSCLI_OUTPUT" | grep -A 2 "API key for" | tail -n 1 | tr -d ' \r\n') || true
    
    if [ -z "$API_KEY" ]; then
        echo ""
        echo "ERROR: Failed to extract API key from cscli output."
        echo "Please check the output above and manually set CROWDSEC_API_KEY in .env:"
        echo "  CROWDSEC_API_KEY=<your-api-key>"
        exit 1
    fi
    
    echo ""
    echo "Generated API key: $API_KEY"
    echo ""
    echo "Adding CROWDSEC_API_KEY to .env file..."
    
    if grep -q '^CROWDSEC_API_KEY=' .env 2>/dev/null; then
        sed -i "s/^CROWDSEC_API_KEY=.*/CROWDSEC_API_KEY=$API_KEY/" .env
    else
        echo "CROWDSEC_API_KEY=$API_KEY" >> .env
    fi
fi

# Always generate the bouncer config file since OpenResty bouncer doesn't resolve env variables
echo "Generating OpenResty bouncer configuration..."
cat <<EOF > docker/crowdsec/crowdsec-openresty-bouncer.conf
API_URL=http://crowdsec:8080
API_KEY=${API_KEY}
EOF

echo "Done. Restart nginx container to apply the new key:"
echo "  docker compose -f docker-compose.prod.yml up -d nginx"
