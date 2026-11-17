/**
 * @file
 * Turns PHP's JSON response string into a Response-like object.
 *
 * The PHP call is synchronous, but fetch() returns a promise, so this does
 * too. A fetch that failed in PHP arrives as an error, and rejects with a
 * TypeError, as the browser's fetch() does, rather than throwing.
 *
 * The outer function runs once, when the binding is installed, before any
 * component, and hands the inner one the built-ins it uses. A component that
 * replaces one later can't see or change another's responses.
 *
 * @param {string} raw - The response, as JSON.
 * @returns {Promise<Object>} A Response-like object with ok, status,
 *   statusText, url, headers, text(), json() and clone(), or a rejection.
 */
// A bare expression, which bindFunction() evaluates to a function.
((parse, string, hasOwn, entries, resolve, iterator, lower, reject, TypeErrorCtor) => (raw) => {
  const response = parse(raw);
  if (hasOwn(response, "error")) {
    return reject(new TypeErrorCtor(`Failed to fetch: ${string(response.error)}`));
  }
  const headers = response.headers || {};
  const bodyText = response.body == null ? "" : string(response.body);
  const build = () => ({
    ok: response.status >= 200 && response.status < 300,
    status: response.status || 200,
    statusText: response.statusText || "",
    url: response.url || "",
    headers: {
      get(name) {
        return hasOwn(headers, lower(string(name))) ? headers[lower(string(name))] : null;
      },
      has(name) {
        return hasOwn(headers, lower(string(name)));
      },
      entries() {
        return entries(headers)[iterator]();
      },
      forEach(callback) {
        const all = entries(headers);
        for (let i = 0; i < all.length; i++) {
          callback(all[i][1], all[i][0]);
        }
      },
      [iterator]: function* () {
        yield* entries(headers);
      },
    },
    // Body readers return promises, as in the fetch API.
    text() {
      return resolve(bodyText);
    },
    // Like a browser's, an empty or malformed body rejects with a
    // SyntaxError.
    json() {
      try {
        return resolve(parse(bodyText));
      } catch (error) {
        return reject(error);
      }
    },
    // @drupal-api-client/json-api-client clones before reading the body.
    // The body is a plain string, so a new object is a faithful clone.
    clone() {
      return build();
    },
  });
  return resolve(build());
})(
  JSON.parse,
  String,
  Object.hasOwn,
  Object.entries,
  Promise.resolve.bind(Promise),
  Symbol.iterator,
  Function.prototype.call.bind(String.prototype.toLowerCase),
  Promise.reject.bind(Promise),
  TypeError,
)
