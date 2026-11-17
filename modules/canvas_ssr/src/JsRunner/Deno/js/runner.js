/**
 * @file
 * The program every Deno process runs, with no permissions.
 *
 * It reads NDJSON commands on stdin and invokes the main module each
 * invoke names. PHP seeds the modules into the runner cache and starts this process
 * with the Deno import map, so each invoke just sends the main module's
 * cache URL.
 *
 * Three rules:
 * - stdout carries only the protocol. console is redirected to stderr below,
 *   since one logged line would break PHP's reader.
 * - It's one file that imports and exports nothing. It's the only file the
 *   Deno process reads from disk, and a component that imports it by its
 *   file URL gets an empty module, not the protocol.
 * - Components see only the ECMAScript globals and the supported web
 *   platform APIs, listed below. Everything else Deno defines is deleted
 *   before any of them loads. See the README's security model.
 *
 * It's plain JavaScript because each runner cache starts empty, so Deno
 * would transpile TypeScript again every time.
 *
 * @see \Drupal\canvas_ssr\JsRunner\Deno\DenoRunner
 * @see \Drupal\canvas_ssr\JsRunner\Deno\ModuleSeeder
 */

/* global Deno */

// Taken before the Deno global is deleted below.
const { exit, inspect, stdin, stdout, stderr } = Deno;

// Private copies of every built-in this script uses after a component can
// run, taken before any can. A component that replaces a global or a
// built-in's method then can't see or change what this script sends PHP,
// or how it resolves import.meta for later components. Nor does anything
// below iterate with for...of, spread or destructuring, which would call
// methods a component can replace. What the language looks up on its own,
// like the then() an await reads, this can't hold on to. The realm setup
// module freezes those before any component runs.
const global = globalThis;
const { apply } = Reflect;
const uncurry = (fn) => (self, ...args) => apply(fn, self, args);
const { stringify, parse } = JSON;
const string = String;
const ErrorCtor = Error;
const TypeErrorCtor = TypeError;
const FunctionCtor = Function;
const PromiseCtor = Promise;
// Node's, which Deno has. Deleted from the globals below, so no component
// can use it.
const runSoon = setImmediate;
// Deleted from the globals below too.
const runLater = setTimeout;
const cancel = clearTimeout;
const { defineProperty, freeze, hasOwn, keys } = Object;
const isArray = Array.isArray;
const encode = uncurry(TextEncoder.prototype.encode);
const decode = uncurry(TextDecoder.prototype.decode);
const subarray = uncurry(Uint8Array.prototype.subarray);
const byteLength = uncurry(
  Object.getOwnPropertyDescriptor(Object.getPrototypeOf(Uint8Array.prototype), 'length').get,
);
const indexOf = uncurry(String.prototype.indexOf);
const slice = uncurry(String.prototype.slice);
const startsWith = uncurry(String.prototype.startsWith);
const endsWith = uncurry(String.prototype.endsWith);
const split = uncurry(String.prototype.split);
const push = uncurry(Array.prototype.push);
const pop = uncurry(Array.prototype.pop);
const join = uncurry(Array.prototype.join);
const sort = uncurry(Array.prototype.sort);
const exec = uncurry(RegExp.prototype.exec);
const writeOut = stdout.writeSync.bind(stdout);
const writeErr = stderr.writeSync.bind(stderr);
const readIn = stdin.readSync.bind(stdin);

// The protocol between DenoProcess and this script.
//
// One JSON object per line: commands on stdin, everything else on stdout.
// JSON escapes newlines inside values, so every newline ends a message.
//
// What PHP sends, matched by `id`:
//
//   { id, type: 'setImportMap', imports }
//   { id, type: 'bind', name, serialize, deserialize }
//   { id, type: 'invoke', main, payload }
//   { id, type: 'preload', urls }
//
// and, matched by `callId`, its reply to a binding call:
//
//   { callId, type: 'call-result', value }
//   { callId, type: 'call-error', message }
//
// What this script sends back:
//
//   { id, type: 'ok' }
//   { id, type: 'preloaded', unloadable }
//   { id, type: 'invoke-result', value }
//   { id, type: 'invoke-error', name, message, stack }
//   { callId, type: 'call', name, arg }
//

const encoder = new TextEncoder();

/**
 * Encodes an outbound message as JSON.
 *
 * JSON.stringify() would look up toJSON() on every object it serializes,
 * and a component could add one to Object.prototype, so this serializes the
 * message's own fields itself. stringify() only does that for objects, and
 * every value here is a string, a number, or an array of strings.
 *
 * @param message The message, in one of the outbound shapes above.
 *
 * @returns The message as JSON.
 */
function encodeMessage(message) {
  const names = keys(message);
  const fields = [];
  for (let i = 0; i < names.length; i++) {
    const value = message[names[i]];
    let json;
    if (isArray(value)) {
      const items = [];
      for (let j = 0; j < value.length; j++) {
        push(items, stringify(string(value[j])));
      }
      json = '[' + join(items, ',') + ']';
    }
    else {
      json = stringify(value);
    }
    push(fields, stringify(names[i]) + ':' + json);
  }

  return '{' + join(fields, ',') + '}';
}

/**
 * Writes text and a newline in full.
 *
 * writeSync() can write less than asked on a full pipe, hence the loop.
 *
 * @param write The stream's writeSync(), bound.
 * @param text The text to write.
 */
function writeLine(write, text) {
  const bytes = encode(encoder, text + '\n');
  const length = byteLength(bytes);
  let written = 0;
  while (written < length) {
    written += write(subarray(bytes, written));
  }
}

/**
 * Writes one message as a line on stdout.
 *
 * @param message The message to send, in one of the outbound shapes above.
 */
function send(message) {
  writeLine(writeOut, encodeMessage(message));
}

/**
 * Writes diagnostics to stderr.
 *
 * @param text The text to write. A newline is appended.
 */
function warn(text) {
  writeLine(writeErr, text);
}

const decoder = new TextDecoder();

const chunk = new Uint8Array(64 * 1024);

/**
 * Text read from stdin that isn't a whole message yet.
 */
let buffered = '';

/**
 * How much of `buffered` has been searched for a newline already.
 */
let searched = 0;

/**
 * Reads the next message from stdin, blocking until it's whole.
 *
 * It's synchronous, so a binding can return PHP's answer directly.
 * It's also the only thing that reads stdin. A partial message left when
 * stdin closes is dropped.
 *
 * @returns The parsed message, or null once stdin has closed.
 */
function readMessageSync() {
  while (true) {
    const newline = indexOf(buffered, '\n', searched);
    if (newline !== -1) {
      const line = slice(buffered, 0, newline);
      buffered = slice(buffered, newline + 1);
      searched = 0;
      if (line !== '') {
        return parse(line);
      }
      continue;
    }
    searched = buffered.length;

    const read = readIn(chunk);
    if (read === null) {
      buffered = '';
      searched = 0;
      return null;
    }
    // Streaming, so a character split across two reads decodes correctly.
    buffered += decode(decoder, subarray(chunk, 0, read), { stream: true });
  }
}

// import.meta.resolve() for the modules this script evaluates.
//
// Deno has its own, but it answers from cache URLs and the Deno import map,
// not the SSR import map. Canvas compiles asset imports to
// `import.meta.resolve('@/...')`, so with Deno's version a component with
// a local image throws on evaluation and falls back to CSR.
//
// This is a port of the HTML spec's "resolve a module specifier".
// ModuleSeeder prepends a preamble that binds it to each module's module
// URL, so relative specifiers resolve from where the browser loads the
// module.
//
// It departs from the spec in three ways, because it runs server-side
// against a map meant for the browser:
//
// 1. URLs are returned exactly as the map writes them, not resolved
//    against a base URL. There's no document URL to resolve against, and
//    module providers register modules under the same URLs.
// 2. Scopes are ignored. Canvas uses them only for "@/components/..."
//    imports, which ComponentModules rewrites to module URLs.
// 3. Deno's resolver isn't used as a fallback. It could only throw or return
//    a cache URL that means nothing outside this process.
//
// See https://html.spec.whatwg.org/#hostgetimportmetaproperties

// "/", "./" and "../" specifiers, which resolve against the base.
const PATH_RELATIVE = /^\.{0,2}\//;

// The scheme and host of an absolute URL, like "https://cdn.example.com", or
// the host of a protocol-relative one, like "//cdn.example.com".
const ORIGIN = /^(?:[a-zA-Z][a-zA-Z\d+\-.]*:)?\/\/[^/]+/;

// Any absolute URL, with or without a host.
const ABSOLUTE = /^[a-zA-Z][a-zA-Z\d+\-.]*:/;

/**
 * The parsed map, most specific key first.
 *
 * @type {Array<[string, ?string]>} Each key and its URL. The URL is null
 *   for a blocked import map entry.
 */
let entries = [];

/**
 * Replaces the import map.
 *
 * @param {object} imports The map's `imports`. Scopes aren't sent.
 */
function setImportMap(imports) {
  const parsed = [];
  const names = keys(imports);
  for (let i = 0; i < names.length; i++) {
    const key = names[i];
    const url = imports[key];
    if (key === '') {
      continue;
    }
    // A non-string URL, or a "prefix/" key whose URL lacks the trailing
    // slash, still matches but throws. A less specific key mustn't answer
    // in its place.
    const blocked = typeof url !== 'string' || (endsWith(key, '/') && !endsWith(url, '/'));
    push(parsed, freeze([key, blocked ? null : url]));
  }
  // Descending order puts the longest matching prefix first.
  entries = sort(parsed, (a, b) => (a[0] > b[0] ? -1 : a[0] < b[0] ? 1 : 0));
}

/**
 * Resolves a "/", "./" or "../" specifier against the importing module's URL.
 *
 * @param {string} specifier The path-relative specifier.
 * @param {string} base The importing module's module URL.
 *
 * @returns {string} The resolved path, in whatever form the base was in.
 */
function resolvePathRelative(specifier, base) {
  const match = exec(ORIGIN, base);
  let origin = match === null ? '' : match[0];
  let path;
  if (startsWith(specifier, '//')) {
    // A protocol-relative specifier keeps only the base's scheme, if it has
    // one, and brings its own host.
    const host = exec(ORIGIN, specifier);
    const hostPart = host === null ? specifier : host[0];
    origin = slice(origin, 0, indexOf(origin, '//')) + hostPart;
    path = slice(specifier, hostPart.length);
  }
  else if (startsWith(specifier, '/')) {
    path = specifier;
  }
  else {
    // Start from the module's directory, which is the root for a base that
    // is only an origin.
    const dir = split(slice(base, origin.length), '/');
    pop(dir);
    push(dir, '');
    path = join(dir, '/');
    if (origin !== '' && path === '') {
      path = '/';
    }
    path += specifier;
  }

  return origin + removeDotSegments(path);
}

/**
 * Drops a path's "." segments, and its ".." segments with the segment before
 * each. A rooted path stays rooted, and a path that ends in either keeps its
 * trailing slash, as in a browser.
 *
 * @param {string} path The path.
 *
 * @returns {string} The path without dot segments.
 */
function removeDotSegments(path) {
  const segments = split(path, '/');
  const out = [];
  let start = 0;
  if (startsWith(path, '/')) {
    start = 1;
    push(out, '');
  }
  const floor = out.length;
  for (let i = start; i < segments.length; i++) {
    const segment = segments[i];
    if (segment !== '.' && segment !== '..') {
      push(out, segment);
      continue;
    }
    if (segment === '..' && out.length > floor) {
      pop(out);
    }
    if (i === segments.length - 1) {
      push(out, '');
    }
  }
  return join(out, '/');
}

/**
 * Looks a resolved specifier up in the import map.
 *
 * @param {string} specifier The specifier, already resolved against the base.
 *
 * @returns {string|undefined} The URL, or undefined when nothing matched.
 *
 * @throws {TypeError} When the matching import map entry is blocked.
 */
function resolveImportsMatch(specifier) {
  for (let i = 0; i < entries.length; i++) {
    const key = entries[i][0];
    const url = entries[i][1];
    if (key === specifier) {
      if (url === null) {
        throw new TypeErrorCtor(`Blocked by a null entry for "${key}" in the import map.`);
      }
      return url;
    }
    if (endsWith(key, '/') && startsWith(specifier, key)) {
      if (url === null) {
        throw new TypeErrorCtor(`Blocked by a null entry for "${key}" in the import map.`);
      }
      return url + slice(specifier, key.length);
    }
  }

  return undefined;
}

/**
 * Resolves a module specifier the way the browser will resolve it.
 *
 * @param {string} specifier The specifier to resolve.
 * @param {string} base The importing module's module URL.
 *
 * @returns {string} The URL the specifier names.
 *
 * @throws {TypeError} When a bare specifier resolves to nothing, as the
 *   browser would.
 */
function resolveImportMeta(specifier, base) {
  // Resolve against the base first, then look the result up, so a rooted key
  // can claim what "./x" resolved to.
  const pathRelative = exec(PATH_RELATIVE, specifier) !== null;
  const resolved = pathRelative ? resolvePathRelative(specifier, base) : specifier;

  const matched = resolveImportsMatch(resolved);
  if (matched !== undefined) {
    return matched;
  }
  // Unmatched URLs and paths resolve to themselves. Only a bare specifier
  // needs the map.
  if (pathRelative || exec(ABSOLUTE, specifier) !== null) {
    return resolved;
  }

  throw new TypeErrorCtor(`Cannot resolve the bare specifier "${specifier}" against the import map.`);
}


let nextCallId = 1;

/**
 * Sends a binding call to PHP and blocks until it answers.
 *
 * PHP only answers calls while it waits for a reply, so the next message
 * must be this call's answer.
 *
 * @param name The binding's name.
 * @param arg What the binding's serializer made of the call.
 *
 * @returns What PHP's callback returned.
 *
 * @throws Error
 *   With the message of whatever PHP's callback threw.
 */
function callHost(name, arg) {
  const callId = nextCallId++;
  send({ callId, type: 'call', name, arg });

  const reply = readMessageSync();
  if (reply === null) {
    throw new ErrorCtor(`stdin closed while "${name}" waited for PHP.`);
  }
  if (reply.callId !== callId) {
    throw new ErrorCtor(`PHP answered call ${callId} with ${stringify(reply)}.`);
  }
  if (reply.type === 'call-error') {
    throw new ErrorCtor(reply.message);
  }

  return reply.value;
}

/**
 * Compiles a binding's serializer or deserializer.
 *
 * The Function constructor sees only globals, so the expression can't reach
 * anything in this script.
 *
 * @param source The JS function expression.
 * @param role Which half of the binding it is, for the error message.
 *
 * @returns The compiled function.
 */
function compileMarshaller(source, role) {
  const compiled = new FunctionCtor(`return (${source});`)();
  if (typeof compiled !== 'function') {
    throw new TypeErrorCtor(`A binding's ${role} must be a function expression.`);
  }

  return compiled;
}

/**
 * Installs a global function that calls PHP.
 *
 * It's synchronous, as ADR 5 decides. A binding that should return a
 * promise, like fetch(), makes one in its deserializer.
 *
 * @param name The global to install.
 * @param serializeSource JS turning the call into a string for PHP.
 * @param deserializeSource JS turning PHP's answer into the call's result.
 */
function installBinding(name, serializeSource, deserializeSource) {
  const serialize = compileMarshaller(serializeSource, 'serializer');
  const deserialize = compileMarshaller(deserializeSource, 'deserializer');

  // Not an arrow function, so the serializer gets the call's `this`. The
  // arguments go on through apply(), since spreading them would iterate.
  const bound = function (...args) {
    const serializerArgs = [this];
    for (let i = 0; i < args.length; i++) {
      push(serializerArgs, args[i]);
    }
    const arg = apply(serialize, undefined, serializerArgs);

    return deserialize(callHost(name, string(arg)));
  };
  defineProperty(bound, 'name', { value: name });

  // No component can replace or delete it, so none can see what later ones
  // pass PHP or get back.
  defineProperty(global, name, {
    value: bound,
    enumerable: true,
    writable: false,
    configurable: false,
  });
}

/**
 * Calls a main module's default export and stringifies what it returns.
 *
 * @param main The main module's cache URL.
 * @param payload The payload, as JSON.
 *
 * @returns Whatever it returned, stringified, with any promise settled.
 */
async function invoke(main, payload) {
  const stop = keepLoopTurning();
  try {
    const module = await import(main);
    if (typeof module.default !== 'function') {
      throw new TypeErrorCtor(`The module registered under "${main}" has no default export to call.`);
    }
    // What the module's evaluation left running, like a promise chain it
    // didn't await, has run before its export is called. A module evaluates
    // once, so otherwise it would run during this call, and not the next.
    await jobsDone();
    const returned = await module.default(payload);

    return string(returned);
  }
  finally {
    stop();
  }
}

/**
 * How many more turns of the loop an invoke with nothing left to run gets.
 */
const IDLE_TURNS = 10;

/**
 * How many milliseconds apart those turns are.
 *
 * Deno 2.9 runs a timer that another timer set no sooner than this, however
 * short a delay it's given.
 */
const IDLE_TURN_MS = 2;

/**
 * Keeps Deno's event loop turning for a while, though nothing is left to run.
 *
 * Deno ends the process when an invoke waits on a promise and nothing is
 * left that could settle it. That's right for a promise that never settles.
 * But Deno 2.9 also does it to a module that awaits as it evaluates, once
 * two modules in the process have thrown after awaiting as they evaluated.
 * The module would finish on the loop's next turn, and there isn't one.
 *
 * A timer gives it one. It's set again a limited number of times, so a
 * promise that never settles still ends the process, 20 ms later.
 *
 * The turns are counted rather than the time since the invoke began. A
 * module can take longer than that to evaluate before it awaits, and it
 * needs the loop to turn after that.
 *
 * @returns A function that stops keeping it turning.
 */
function keepLoopTurning() {
  let turns = IDLE_TURNS;
  let timer = runLater(function turn() {
    timer = --turns > 0 ? runLater(turn, IDLE_TURN_MS) : null;
  }, IDLE_TURN_MS);

  return () => {
    turns = 0;
    if (timer !== null) {
      cancel(timer);
    }
  };
}

/**
 * Waits until the job queue is empty.
 *
 * An immediate callback runs only once no job is left, so a promise chain
 * an invoke started but didn't wait for has run by then. A timer would do
 * too, but Deno clamps it to milliseconds, since the loop that reads
 * commands runs in one. Otherwise it would
 * run during a later invoke, between what that invoke awaits, and could
 * change what it sees, or call a binding while PHP isn't waiting for one.
 * Components have no timers, so the only other thing that can still be
 * pending is a module an import() is loading, which Renderer waits for.
 *
 * @returns A promise that settles once the queue is empty.
 */
function jobsDone() {
  return new PromiseCtor((resolve) => {
    runSoon(resolve);
  });
}

/**
 * Imports the preload modules so Deno fills its caches, when building a
 * base cache.
 *
 * Only a module whose imports can't load is reported, since it fails the
 * same way in every realm. Other failures may come from the build having no
 * bindings, so a render may not repeat them.
 *
 * @param urls The cache URLs to import, in order.
 *
 * @returns The cache URLs of the unloadable ones: each imports a module
 *   nothing provides, or one that doesn't parse. Deno reports both as
 *   ERR_MODULE_NOT_FOUND.
 */
async function preload(urls) {
  const unloadable = [];
  for (let i = 0; i < urls.length; i++) {
    const url = urls[i];
    try {
      await import(url);
    } catch (error) {
      if (error?.code === 'ERR_MODULE_NOT_FOUND') {
        push(unloadable, url);
      }
    }
  }

  return unloadable;
}

/**
 * Answers one command from PHP.
 *
 * The read loop awaits this, so while an invoke runs, only callHost() reads
 * stdin.
 *
 * @param command The command to answer, in one of the inbound shapes the
 *   protocol lists.
 */
async function dispatch(command) {
  switch (command.type) {
    case 'setImportMap':
      setImportMap(command.imports);
      send({ id: command.id, type: 'ok' });
      return;

    case 'bind':
      installBinding(command.name, command.serialize, command.deserialize);
      send({ id: command.id, type: 'ok' });
      return;

    case 'preload':
      send({ id: command.id, type: 'preloaded', unloadable: await preload(command.urls) });
      return;

    case 'invoke':
      try {
        const value = await invoke(command.main, command.payload);
        await jobsDone();
        send({ id: command.id, type: 'invoke-result', value });
      }
      catch (error) {
        await jobsDone();
        send({
          id: command.id,
          type: 'invoke-error',
          name: errorField(error, 'name'),
          message: errorField(error, 'message'),
          stack: errorField(error, 'stack'),
        });
      }
  }
}

/**
 * Reads a field from whatever was thrown, which may not be an Error.
 *
 * @param error What was thrown.
 * @param field The field to read: "name", "message" or "stack".
 *
 * @returns Its value as a string, or "" when there is none.
 */
function errorField(error, field) {
  if (error instanceof ErrorCtor) {
    return string(error[field] ?? '');
  }
  if (field === 'message') {
    return string(error);
  }

  return field === 'name' ? 'Error' : '';
}

// Send all console output to stderr, which PHP adds to error messages.
// Deno's own console writes some methods to stdout.
const consoleToStderr = Object.fromEntries(
  Object.keys(console).map((method) => [
    method,
    (...args) => {
      const parts = [];
      for (let i = 0; i < args.length; i++) {
        push(parts, typeof args[i] === 'string' ? args[i] : inspect(args[i]));
      }
      warn(`[${method}] ` + join(parts, ' '));
    },
  ]),
);
Object.defineProperty(globalThis, 'console', {
  value: consoleToStderr,
  configurable: true,
  writable: true,
});

// Deno exits on an unhandled rejection. Log it and carry on instead.
globalThis.addEventListener('unhandledrejection', (event) => {
  event.preventDefault();
  warn(`canvas_ssr: a rejection nothing handled: ${errorField(event.reason, 'message')}`);
});

// It exits on an error nothing caught too, which is what a
// queueMicrotask() callback throws. A browser reports both and carries on.
globalThis.addEventListener('error', (event) => {
  event.preventDefault();
  warn(`canvas_ssr: an error nothing caught: ${event.message}`);
});

// Deno keeps the global object's listeners in a plain object, under a
// symbol-keyed global any component can read. Unless they're locked, a
// component could drop a listener above, so a later island's unhandled
// rejection or uncaught error ends the process, or add one that sees later
// islands'. So the entry for each is frozen, and the object is put behind
// a proxy that refuses to stop taking new entries, or to change its
// prototype, so components can still listen for events of their own.
// If Deno keeps them elsewhere, there's nothing to lock, and the Deno
// runner's tests say so.
for (const key of Object.getOwnPropertySymbols(globalThis)) {
  const data = globalThis[key];
  if (typeof data !== 'object' || data === null || !hasOwn(data, 'listeners')
    || !hasOwn(data.listeners, 'unhandledrejection') || !hasOwn(data.listeners, 'error')) {
    continue;
  }
  const listeners = new Proxy(data.listeners, {
    preventExtensions: () => false,
    setPrototypeOf: () => false,
  });
  for (const type of ['unhandledrejection', 'error']) {
    const entry = listeners[type];
    for (let i = 0; i < entry.length; i++) {
      freeze(entry[i].options);
      freeze(entry[i]);
    }
    freeze(entry);
    defineProperty(listeners, type, { writable: false, configurable: false });
  }
  defineProperty(data, 'listeners', { value: listeners, writable: false, configurable: false });
}

// The ECMAScript globals, which are the language, not the host.
// WebAssembly isn't one: it's a host API, and isn't supported.
//
// Nor is SharedArrayBuffer, though it's in the language. Browsers only
// give it to cross-origin isolated pages, which a Drupal site's aren't, so
// components can't use it in the browser either. Without it, Atomics.wait()
// has nothing to wait on. It would block without using CPU, which no time
// limit but the wall clock's can end, so a process PHP lost could wait
// forever.
//
// Nor is FinalizationRegistry. Its callbacks run once the collector frees
// an object, which may be during a later island than the one that
// registered it. That's code no island can be held to account for. It
// cleans up what outlives a page, which a render's work doesn't.
const ECMASCRIPT_GLOBALS = [
  'AggregateError', 'Array', 'ArrayBuffer', 'AsyncDisposableStack', 'Atomics',
  'BigInt', 'BigInt64Array', 'BigUint64Array', 'Boolean', 'DataView', 'Date',
  'DisposableStack', 'Error', 'EvalError', 'Float16Array', 'Float32Array',
  'Float64Array', 'Function', 'Infinity', 'Int8Array', 'Int16Array',
  'Int32Array', 'Intl', 'Iterator', 'JSON', 'Map', 'Math', 'NaN', 'Number',
  'Object', 'Promise', 'Proxy', 'RangeError', 'ReferenceError', 'Reflect',
  'RegExp', 'Set', 'String', 'SuppressedError', 'Symbol', 'SyntaxError',
  'Temporal', 'TypeError', 'URIError', 'Uint8Array', 'Uint8ClampedArray',
  'Uint16Array', 'Uint32Array', 'WeakMap', 'WeakRef', 'WeakSet', 'decodeURI',
  'decodeURIComponent', 'encodeURI', 'encodeURIComponent', 'escape', 'eval',
  'globalThis', 'isFinite', 'isNaN', 'parseFloat', 'parseInt', 'undefined',
  'unescape',
];

// The web platform APIs supported during SSR. The list can evolve as
// components need more. Each addition is an API every component gets, so
// it's kept to ones that only compute, and console, which goes to stderr.
// performance is cut down to a clock below.
const WEB_PLATFORM_GLOBALS = [
  'DOMException', 'Headers', 'TextDecoder', 'TextEncoder', 'URL',
  'URLSearchParams', 'atob', 'btoa', 'console', 'performance',
  'queueMicrotask',
];

// performance without the timeline, whose entries pile up until the process
// ends.
const { timeOrigin } = performance;
const now = performance.now.bind(performance);
Object.defineProperty(globalThis, 'performance', {
  value: Object.freeze({ now: () => now(), timeOrigin }),
  configurable: true,
  writable: true,
});

// Delete every other global Deno defines: its namespace, Node's globals,
// and the web platform APIs that reach outside the render, like Worker,
// fetch, timers, WebSocket, storage that Deno keeps on disk, and WebGPU.
// The Deno import map blocks node: modules. Everything this script needs
// from them was taken above.
//
// Symbol-keyed globals stay: Deno brands the global object with them, and
// its event dispatch fails without them. So does its prototype chain, which
// keeps addEventListener() and dispatchEvent(). They reach only events in
// this realm.
const supported = new Set([...ECMASCRIPT_GLOBALS, ...WEB_PLATFORM_GLOBALS]);
for (const name of Object.getOwnPropertyNames(globalThis)) {
  if (!supported.has(name)) {
    delete globalThis[name];
  }
}

// Deno's queueMicrotask() replaces itself on its first call, by deleting the
// global and defining it again. That would throw once the realm setup module
// has locked the global, so it's done now.
queueMicrotask(() => {});

// Deno defines some globals, like TextEncoder and atob, as getters that
// load them on first use. Make them plain values, as in a browser, so the
// realm setup module freezes what they hold.
for (const name of Object.getOwnPropertyNames(globalThis)) {
  const descriptor = Object.getOwnPropertyDescriptor(globalThis, name);
  if (descriptor.get !== undefined) {
    Object.defineProperty(globalThis, name, {
      value: globalThis[name],
      enumerable: descriptor.enumerable,
      configurable: true,
      writable: true,
    });
  }
}

// The resolver the import.meta.resolve preamble calls. Not writable, so no
// component can answer for later ones.
Object.defineProperty(globalThis, '__canvasSsrResolveImportMeta', {
  value: resolveImportMeta,
});

let message;
while ((message = readMessageSync()) !== null) {
  if (hasOwn(message, 'callId')) {
    warn(`canvas_ssr: no call is waiting for reply ${message.callId}.`);
    continue;
  }
  await dispatch(message);
}

// stdin closed, so PHP is done with this process, or gone. Exit rather
// than wait on whatever is still pending.
exit(0);
