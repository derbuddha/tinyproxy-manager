<?php
/**
 * Shared helpers for the Tinyproxy filter lists (allowed-domains.txt and
 * blocked-domains.txt).
 *
 * A filter line may carry an optional trailing comment:
 *
 *     github.com  # needed for git clone
 *
 * Tinyproxy's own filter parser cuts every line at the first whitespace or at
 * the first unescaped '#', so the comment never reaches the filter engine - it
 * is documentation for whoever maintains the list. That also means a domain
 * pattern itself can never contain a space or a bare '#'.
 */

/**
 * Splits a filter line into its domain part and its trailing comment.
 * A blank line or a full-line comment ("# ...") yields an empty domain, which
 * is how callers recognize lines that are not list entries.
 */
function parseDomainLine($line) {
    $line = trim($line);
    $hash = -1;

    for ($i = 0, $len = strlen($line); $i < $len; $i++) {
        // A '#' escaped as "\#" is a literal character, not a comment marker -
        // same rule Tinyproxy applies.
        if ($line[$i] === '#' && ($i === 0 || $line[$i - 1] !== '\\')) {
            $hash = $i;
            break;
        }
    }

    if ($hash === -1) {
        return ['domain' => $line, 'comment' => ''];
    }

    return [
        'domain'  => rtrim(substr($line, 0, $hash)),
        'comment' => trim(substr($line, $hash + 1)),
    ];
}

/**
 * Collapses a comment to a single harmless line. Newlines would split the entry
 * into a second - and then unparseable - filter line.
 */
function sanitizeDomainComment($comment) {
    // The value comes straight from a JSON request body, so it isn't
    // necessarily a string.
    if (!is_scalar($comment)) {
        return '';
    }

    $comment = preg_replace('/\s+/u', ' ', (string) $comment);
    $comment = trim($comment);

    if (mb_strlen($comment) > 200) {
        $comment = rtrim(mb_substr($comment, 0, 200));
    }

    return $comment;
}

/**
 * Renders a domain plus optional comment back into a filter line.
 */
function formatDomainLine($domain, $comment) {
    $domain = trim($domain);
    $comment = sanitizeDomainComment($comment);

    return $comment === '' ? $domain : $domain . '  # ' . $comment;
}
