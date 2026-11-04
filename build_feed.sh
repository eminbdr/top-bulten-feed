#!/bin/bash
# Builds site/ for Cloudflare Pages: feed.xml, images/, sources.json, icons/.
# State comes from the live site (no feed files are committed here).
#   ACCOUNTS=a,b ./build_feed.sh   only these accounts (a user's refresh)
#   ./build_feed.sh                all accounts in sources.json
set -euo pipefail
BASE_DIR="$(cd "$(dirname "$0")" && pwd)"
SITE="$BASE_DIR/site"
RAW_DIR="$BASE_DIR/.raw_feeds"
RSS_BRIDGE_DIR="${RSS_BRIDGE_DIR:-$BASE_DIR/rss-bridge}"
FEED_SITE="${FEED_SITE:-https://top-bulten-feed.pages.dev}"

rm -rf "$RAW_DIR" "$SITE"
mkdir -p "$RAW_DIR" "$SITE/images"
cp "$BASE_DIR/sources.json" "$BASE_DIR/_headers" "$SITE/"
cp -r "$BASE_DIR/icons" "$SITE/"

# 1. Current feed and the images it uses, from the live site.
if curl -fsSL "$FEED_SITE/feed.xml" -o "$SITE/feed.xml"; then
  curl -fsSL "$FEED_SITE/feed_metadata.json" -o "$SITE/feed_metadata.json" || true
  grep -oE 'images/[0-9a-f]{32}\.jpg' "$SITE/feed.xml" | sort -u \
    | xargs -r -P 8 -I{} sh -c 'curl -fsSL "$0/{}" -o "$1/{}" || echo "missing {}"' "$FEED_SITE" "$SITE"
else
  echo "No live feed yet: starting from an empty feed"
  rm -f "$SITE/feed.xml"
fi

if [ ! -d "$RSS_BRIDGE_DIR" ]; then
  git clone --depth 1 https://github.com/RSS-Bridge/rss-bridge.git "$RSS_BRIDGE_DIR"
  rm -rf "$RSS_BRIDGE_DIR/.git"
fi

# 2. Which accounts. ACCOUNTS from a refresh is trusted only for names that
#    are in sources.json.
mapfile -t ALL_USERS < <(jq -r '.instagram | keys[]' "$BASE_DIR/sources.json")
USERS=("${ALL_USERS[@]}")
PARTIAL=0
if [ -n "${ACCOUNTS:-}" ]; then
  PARTIAL=1
  USERS=()
  IFS=',' read -ra REQUESTED <<< "$ACCOUNTS"
  for u in "${REQUESTED[@]}"; do
    for a in "${ALL_USERS[@]}"; do [ "$u" = "$a" ] && USERS+=("$a"); done
  done
  [ ${#USERS[@]} -gt 0 ] || { echo "No known accounts in ACCOUNTS=$ACCOUNTS"; exit 1; }
  echo "Partial update: ${USERS[*]}"
fi

# 3. Fetch. Direct RSS feeds only on full runs.
if [ $PARTIAL = 0 ]; then
  DIRECT_FEEDS=("https://www.elyazmalari.com/feed")
  i=0
  for url in "${DIRECT_FEEDS[@]}"; do
    echo "Fetching direct feed: $url"
    curl -sL "$url" > "$RAW_DIR/direct_$i.xml" || true
    i=$((i + 1))
  done
fi
for user in "${USERS[@]}"; do
  echo "Fetching Instagram: @$user"
  php "$RSS_BRIDGE_DIR/index.php" action=display bridge=Instagram context=Username \
    u="$user" media_type=all format=Mrss > "$RAW_DIR/insta_$user.xml" &
done
wait

# 4. Merge (keeps other / failed accounts' posts) and localize images.
php "$BASE_DIR/merge_feed.php" "$SITE/feed.xml" "$RAW_DIR" "$(IFS=,; echo "${USERS[*]}")" "$PARTIAL"
SITE_DIR="$SITE" python3 "$BASE_DIR/process_images.py"
echo "site ready: $(grep -o '<item' "$SITE/feed.xml" | wc -l) posts, $(ls "$SITE/images" | wc -l) images"
