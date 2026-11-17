/**
 * @file
 * Turns a fetch() call into a JSON string for PHP.
 *
 * The outer function runs once, when the binding is installed, before any
 * component, and hands the inner one the built-ins it uses. A component that
 * replaces one later can't see or change another's requests.
 *
 * The string also carries the call stack, which names the modules whose
 * code made the call. PHP uses it to tell which modules may keep what the
 * fetch got. The realm setup module makes the stack deep enough to reach a
 * component past a library's own frames, and the realm lock keeps it so.
 *
 * @param {*} thisVal - The call's `this`, unused.
 * @param {string|URL|Request} input - A URL, as a string or a URL object,
 *   or a Request-like object.
 * @param {RequestInit} [init] - The fetch options, if any.
 * @returns {string} The request, as JSON.
 */
// A bare expression, which bindFunction() evaluates to a function.
((stringify, string, entries, arrayFrom, iterator, upper, lower, StackError, create, hasOwn) => (thisVal, input, init) => {
  // As in the Fetch standard, the method is GET unless the call gives
  // another. PHP refuses a request without one. No body, since only GET and
  // HEAD are allowed.
  const req = { url: "", method: "GET", headers: create(null), stack: string(new StackError().stack) };
  // The Fetch standard's "normalize a method": these are upper-cased, and
  // anything else is left as is. So "get" becomes "GET" but "patch" stays
  // lowercase, as in a browser.
  const normalizeMethod = (method) => {
    const raised = upper(string(method));
    switch (raised) {
      case "DELETE":
      case "GET":
      case "HEAD":
      case "OPTIONS":
      case "POST":
      case "PUT":
        return raised;
    }
    return string(method);
  };
  const applyInit = (source) => {
    if (source.method != null) req.method = normalizeMethod(source.method);
    if (source.headers) {
      // As in a browser, the init's headers replace a Request's rather than
      // adding to them, and a name given more than once, in any case, keeps
      // each value, joined by a comma and a space.
      req.headers = create(null);
      // A Headers object or a list of pairs is iterable, as the Fetch
      // standard reads it. Any other object is a record.
      const all = typeof source.headers[iterator] === "function"
        ? arrayFrom(source.headers)
        : entries(source.headers);
      for (let i = 0; i < all.length; i++) {
        const name = lower(string(all[i][0]));
        const value = string(all[i][1]);
        req.headers[name] = hasOwn(req.headers, name) ? `${req.headers[name]}, ${value}` : value;
      }
    }
  };
  if (typeof input === "string") {
    req.url = input;
  } else if (input && typeof input === "object") {
    // A Request has a url, and a URL has an href.
    if (input.url != null) req.url = string(input.url);
    else if (input.href != null) req.url = string(input.href);
    applyInit(input);
  }
  if (init && typeof init === "object") {
    applyInit(init);
  }
  return stringify(req);
})(
  JSON.stringify,
  String,
  Object.entries,
  Array.from,
  Symbol.iterator,
  Function.prototype.call.bind(String.prototype.toUpperCase),
  Function.prototype.call.bind(String.prototype.toLowerCase),
  Error,
  Object.create,
  Object.hasOwn,
)
