<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

/**
 * Canonicalizes the configured frontend URL for security-relevant consumers.
 *
 * The frontend_url setting feeds an iframe src, a trusted redirect target,
 * and the origin postMessage checks compare against. Two hazards make a
 * naive parse unsafe:
 *
 * - Parser differentials. PHP and browsers disagree about what a URL's host
 *   is, in two distinct ways, and both let the browser navigate to one
 *   origin while Drupal derives another — so the iframe carries the
 *   activation assertion to a site whose messages the host would then
 *   refuse, or worse, that was never the configured site at all.
 *   - Structure: in "https://evil.test\@trusted.example", parse_url reads
 *     the host as trusted.example (everything before the backslash being
 *     userinfo), while a browser folds the backslash to a slash and loads
 *     evil.test. Backslashes, credentials, queries, and fragments have no
 *     place in a base URL and are refused outright.
 *   - Host spelling: browsers rewrite hosts before forming an origin. They
 *     decode percent-encoding, collapse the IPv4 number forms ("0x7f000001",
 *     "2130706433", "127.1" all become "127.0.0.1"), and convert
 *     internationalized names to their ASCII "xn--" form. PHP preserves the
 *     original spelling. Rather than reimplement the URL standard's host
 *     parser, this class accepts only hosts *already* in the form a browser
 *     would produce, and refuses every other spelling. An internationalized
 *     site is configured by its ASCII name — which is exactly the string its
 *     MessageEvents will report.
 * - Divergent derivation. The origin and the draft URL must describe the
 *   same site, so both are built from one canonical parse here rather than
 *   from the raw string in two places.
 *
 * The config schema restricts the setting to this same shape on save; this
 * class is the runtime counterpart, so a value that bypassed validation (a
 * settings.php override, a direct config write) still cannot smuggle an
 * executable scheme or an ambiguous host into any sink. Consumers treat
 * NULL as "no frontend configured" and fail closed.
 */
final class FrontendUrl {

  private function __construct(
    /**
     * The canonical base URL.
     *
     * This includes scheme, lowercased host, non-default port, and path, with
     * no trailing slash. The draft path is appended to this.
     */
    public readonly string $baseUrl,
    /**
     * The origin postMessage checks compare against.
     *
     * This includes scheme, host, and non-default port. The iframe embedder
     * allowlist compares against the same value.
     */
    public readonly string $origin,
  ) {}

  /**
   * Parses and canonicalizes the configured frontend URL.
   *
   * @param string $frontend_url
   *   The configured frontend URL.
   *
   * @return self|null
   *   The canonical representation, or NULL when the value is not an
   *   unambiguous absolute http(s) URL whose host is already spelled the
   *   way a browser would spell it, and which carries no credentials,
   *   query, fragment, backslash, or trailing slash.
   */
  public static function fromConfig(string $frontend_url): ?self {
    // Reject the characters browsers and PHP's parser disagree on, or that
    // do not belong in a base URL, before parse_url ever runs: a backslash
    // (the parser-differential vector), embedded credentials (@), a query
    // (?), a fragment (#), and whitespace.
    if (preg_match('~[\\\\?#@\s]~', $frontend_url) === 1) {
      return NULL;
    }
    $parts = parse_url($frontend_url);
    if (!\is_array($parts)) {
      return NULL;
    }
    $scheme = strtolower($parts['scheme'] ?? '');
    if (!\in_array($scheme, ['http', 'https'], TRUE)) {
      return NULL;
    }
    // parse_url can still surface these on some inputs; refuse them even
    // though the character scan above already rejects their delimiters.
    foreach (['user', 'pass', 'query', 'fragment'] as $forbidden) {
      if (isset($parts[$forbidden])) {
        return NULL;
      }
    }
    // Lowercasing is the one host rewrite that is safe to perform rather
    // than refuse: browsers lowercase ASCII hosts, so the result is what a
    // MessageEvent will report. Every other rewrite they do is refused.
    $host = strtolower($parts['host'] ?? '');
    if (!self::isCanonicalHost($host)) {
      return NULL;
    }
    $path = $parts['path'] ?? '';
    // The draft path is appended verbatim, so a trailing slash here would
    // produce a double slash in every generated URL. A bare "/" path counts.
    if (str_ends_with($path, '/')) {
      return NULL;
    }

    // A scheme-default port is dropped: browsers omit it from an origin's
    // ASCII serialization, so a MessageEvent from an app served at
    // "https://example.com:443" reports origin "https://example.com".
    // Keeping the explicit port would make the host's strict origin
    // comparison reject every message and stall the preview.
    $origin = $scheme . '://' . $host;
    $default_ports = ['http' => 80, 'https' => 443];
    if (isset($parts['port']) && $default_ports[$scheme] !== $parts['port']) {
      $origin .= ':' . $parts['port'];
    }

    return new self($origin . $path, $origin);
  }

  /**
   * Whether the host is already spelled the way a browser would spell it.
   *
   * Accepts exactly two shapes, both of which survive a browser's host
   * parser unchanged:
   *
   * - A canonical dotted-quad IPv4 literal. filter_var() is precise here:
   *   it refuses the alternative number forms browsers would rewrite —
   *   hex ("0x7f000001"), decimal ("2130706433"), octal ("0177.0.0.1"),
   *   short ("127.1") — and out-of-range octets.
   * - A DNS name of ASCII letter/digit/hyphen labels whose final label
   *   begins with a letter. The letter requirement is what refuses the
   *   remaining IPv4 number forms: per the URL standard a host whose last
   *   label parses as a number is an IPv4 address, so "0x7f000001" and
   *   "2130706433" are addresses to a browser and names to PHP.
   *
   * Refused as a consequence, and deliberately: percent-encoding (a browser
   * decodes it), non-ASCII (a browser converts it to the ASCII "xn--" form,
   * so configure that form instead), trailing dots, and IPv6 literals (whose
   * canonical serialization is its own problem, and which no frontend
   * deployment needs).
   */
  private static function isCanonicalHost(string $host): bool {
    if ($host === '' || \strlen($host) > 253) {
      return FALSE;
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== FALSE) {
      return TRUE;
    }
    // Letter/digit/hyphen labels, no leading or trailing hyphen, each at
    // most 63 octets; the final label must start with a letter.
    $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
    $tld = '[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?';
    return preg_match("~^(?:$label\\.)*$tld$~", $host) === 1;
  }

}
