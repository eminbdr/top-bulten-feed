<?php
// Merges freshly fetched feeds into the published feed.xml.
//
//   php merge_feed.php FEED_XML RAW_DIR USERS_CSV PARTIAL
//
// RAW_DIR holds insta_<user>.xml (one per fetched Instagram account) and
// direct_*.xml (plain RSS feeds). Kept from the current FEED_XML:
//   - partial run (a user's refresh): every account that was not refreshed
//   - full run: accounts whose fetch failed, so a temporary Instagram error
//     does not empty an account
// An insta_<user>.xml whose channel is not "<user> - Instagram" (e.g. an
// RSS-Bridge error feed) counts as a failed fetch.

[$_, $feedPath, $rawDir, $usersCsv, $partialArg] = $argv + [null, null, null, '', '0'];
$users = array_values(array_filter(explode(',', $usersCsv), 'strlen'));
$partial = $partialArg === '1';
libxml_use_internal_errors(true);

function account_of(string $title): string
{
    return preg_replace('/\s+-\s+\S+$/u', '', trim($title));
}

function items_of(DOMDocument $doc): array
{
    $out = [];
    foreach (['item', 'entry'] as $tag) {
        foreach ($doc->getElementsByTagName($tag) as $node) {
            $out[] = $node;
        }
    }
    return $out;
}

function channel_title(DOMDocument $doc): string
{
    $xp = new DOMXPath($doc);
    $node = $xp->query('/rss/channel/title | /*[local-name()="feed"]/*[local-name()="title"]')->item(0);
    return $node ? trim($node->textContent) : 'News Source';
}

function source_title(DOMElement $item): ?string
{
    foreach ($item->childNodes as $child) {
        if ($child instanceof DOMElement && $child->nodeName === 'sourceTitle') {
            return trim($child->textContent);
        }
    }
    return null;
}

function load(string $path): ?DOMDocument
{
    if (!is_file($path) || filesize($path) === 0) {
        return null;
    }
    $doc = new DOMDocument();
    return $doc->load($path, LIBXML_NONET) ? $doc : null;
}

$out = new DOMDocument('1.0', 'UTF-8');
$rss = $out->appendChild($out->createElement('rss'));
$rss->setAttribute('version', '2.0');
$channel = $rss->appendChild($out->createElement('channel'));
$channel->appendChild($out->createElement('title'))->appendChild($out->createTextNode('Unified Feed'));

$add = function (DOMElement $item, string $title) use ($out, $channel) {
    $node = $out->importNode($item, true);
    if (source_title($item) === null) {
        $node->appendChild($out->createElement('sourceTitle'))->appendChild($out->createTextNode($title));
    }
    $channel->appendChild($node);
};

$refreshed = [];
$fresh = 0;
foreach (glob(rtrim($rawDir, '/') . '/*.xml') as $file) {
    $doc = load($file);
    $name = basename($file);
    if (!$doc) {
        echo "skip $name: not a feed\n";
        continue;
    }
    $title = channel_title($doc);
    $items = items_of($doc);
    if (preg_match('/^insta_(.+)\.xml$/', $name, $m)) {
        if (account_of($title) !== $m[1] || !$items) {
            echo "skip $name: fetch failed ($title, " . count($items) . " items)\n";
            continue;
        }
        $refreshed[$m[1]] = true;
    }
    foreach ($items as $item) {
        $add($item, $title);
        $fresh++;
    }
}

$kept = 0;
$current = load($feedPath);
if ($current) {
    foreach (items_of($current) as $item) {
        $account = account_of(source_title($item) ?? '');
        $keep = $partial
            ? !isset($refreshed[$account])
            : in_array($account, $users, true) && !isset($refreshed[$account]);
        if ($keep) {
            $add($item, $account);
            $kept++;
        }
    }
}

$failed = array_values(array_diff($users, array_keys($refreshed)));
echo sprintf(
    "merged: %d fresh, %d kept, refreshed: %s%s\n",
    $fresh,
    $kept,
    implode(',', array_keys($refreshed)) ?: '-',
    $failed ? ' | failed (old posts kept): ' . implode(',', $failed) : ''
);
$out->save($feedPath);
