UNIT="${1:-A1}"
KEY="xxxxxxx"  # <- tvoj admin key
URL="http://localhost/app/admin/api/integrations/pull_now.php?unit=${UNIT}&platform=booking&key=${KEY}"
curl -s "${URL}" | jq .
