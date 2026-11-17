/**
 * @file
 * Locks the realm before any component's code runs.
 *
 * Islands share a realm, so a component that patched a built-in or replaced
 * a global would change every island rendered after it, on other pages and
 * for other users once cached. This module is the first import of every main
 * module, and the base's first preload module, so it evaluates before any
 * other module in the realm. It:
 *
 * 1. Keeps the global object and console extensible, as a browser keeps
 *    its window (see guardGlobalObject()), and tames the configured
 *    properties (see TAMED_GLOBALS).
 * 2. Freezes every built-in: whatever the globals reach, through properties,
 *    accessors and prototypes, plus the built-ins only syntax reaches, like
 *    the generator prototypes. The console object stays writable, so a
 *    component that silences a console method still renders, but its
 *    methods are put back before the next island.
 * 3. Makes every existing global non-writable and non-configurable.
 *    Components can still add globals of their own while they render. The
 *    global object's prototype chain is frozen too, and the prototype
 *    itself is put back before every island (see restoreShared()).
 * 4. Defines drupalSettings, which no island can redefine or delete, so
 *    every island can be given its own (see setDrupalSettings()). A module
 *    that evaluates during a render gets only site data (see
 *    evaluationView()).
 * 5. Records the globals that exist, and deletes any other a render adds,
 *    before the next island (see beginIsland()). Those a library adds while
 *    it evaluates are kept, since it evaluates only once, and put back if a
 *    render reassigned or deleted them. A component's module may not change
 *    the globals, console or preact's options as it evaluates (see
 *    settleShared()).
 *
 * A component that writes to a frozen built-in then throws, and its island
 * falls back to CSR.
 *
 * Every other module's exports are frozen too, once it evaluates: lines
 * appended to it call freezeExports() or holdExports() below, and the
 * framework renderer calls freezeDeferred().
 *
 * canvas_ssr.services.yml says how to configure taming.
 *
 * @see \Drupal\canvas_ssr\JsModules\TamedProperties
 * @see \Drupal\canvas_ssr\JsModules\ModuleEvaluationHooks
 */

/**
 * The global properties to tame, from canvas_ssr.tamed_properties and
 * canvas_ssr.additional_tamed_properties.
 *
 * Each key is a path to an object: a global's name, or a built-in only
 * syntax reaches, like "%IteratorPrototype%", followed by property names.
 * Each value lists the object's properties to tame: a name, a well-known
 * symbol like "[Symbol.iterator]", "prefix*" for every name with the
 * prefix, or "*" for every own property.
 *
 * Freezing an object makes assigning to its properties throw, even on an
 * object that only inherits them. So `error.name = 'Custom'` would throw,
 * since Error.prototype.name is frozen. That's the override mistake. A
 * tamed property becomes an accessor whose setter defines an own property on
 * the object assigned to, and throws only for the frozen object itself.
 *
 * PHP replaces the placeholder below.
 */
const TAMED_GLOBALS = {} /* tamed globals */;

const {
  defineProperty,
  freeze,
  getOwnPropertyDescriptor,
  getOwnPropertyDescriptors,
  getOwnPropertyNames,
  getPrototypeOf,
  hasOwn,
  preventExtensions,
  setPrototypeOf,
} = Object;
const { apply, ownKeys } = Reflect;
// For a job that runs before any queued after it, whatever a component does
// to Promise later.
const promiseThen = Promise.prototype.then;
const resolvedPromise = Promise.resolve();
const reflectPreventExtensions = Reflect.preventExtensions;

// %TypedArray%.prototype's getters, which tell a typed array from anything
// else, and give its length.
const typedArrayPrototype = getPrototypeOf(Uint8Array.prototype);
const typedArrayTag = getOwnPropertyDescriptor(typedArrayPrototype, Symbol.toStringTag).get;
const typedArrayLength = getOwnPropertyDescriptor(typedArrayPrototype, 'length').get;

// The global object's prototype, which restoreShared() puts back.
const globalPrototype = getPrototypeOf(globalThis);

// %RegExp.prototype.source%'s getter, which throws for anything but a
// regular expression and RegExp.prototype.
const regExpSource = getOwnPropertyDescriptor(RegExp.prototype, 'source').get;

// The console object, which isn't frozen. Undefined if there's none.
const consoleObject = typeof globalThis.console === 'object' && globalThis.console !== null
  ? globalThis.console
  : undefined;

/**
 * Finds the built-ins that no global reaches, by SES's names for them.
 *
 * @returns {Object<string, object>}
 *   Each built-in this engine has, by name.
 *
 * @see https://github.com/endojs/endo/blob/master/packages/ses/src/get-anonymous-intrinsics.js
 */
function anonymousIntrinsics() {
  const intrinsics = {};
  const add = (name, get) => {
    try {
      const value = get();
      if ((typeof value === 'object' && value !== null) || typeof value === 'function') {
        intrinsics[name] = value;
      }
    }
    catch {
      // This engine lacks it.
    }
  };
  const arrayIterator = [][Symbol.iterator]();
  add('%ArrayIteratorPrototype%', () => getPrototypeOf(arrayIterator));
  add('%IteratorPrototype%', () => getPrototypeOf(getPrototypeOf(arrayIterator)));
  add('%MapIteratorPrototype%', () => getPrototypeOf(new Map()[Symbol.iterator]()));
  add('%SetIteratorPrototype%', () => getPrototypeOf(new Set()[Symbol.iterator]()));
  add('%StringIteratorPrototype%', () => getPrototypeOf(''[Symbol.iterator]()));
  add('%RegExpStringIteratorPrototype%', () => getPrototypeOf(/a/g[Symbol.matchAll]('a')));
  add('%IteratorHelperPrototype%', () => getPrototypeOf(arrayIterator.map((x) => x)));
  add('%WrapForValidIteratorPrototype%', () => getPrototypeOf(Iterator.from({ next() {} })));
  add('%TypedArray%', () => getPrototypeOf(Uint8Array));
  add('%Generator%', () => getPrototypeOf(function* () {}));
  add('%GeneratorFunction%', () => getPrototypeOf(function* () {}).constructor);
  add('%GeneratorPrototype%', () => getPrototypeOf(function* () {}).prototype);
  add('%AsyncFunction%', () => getPrototypeOf(async function () {}).constructor);
  add('%AsyncGenerator%', () => getPrototypeOf(async function* () {}));
  add('%AsyncGeneratorFunction%', () => getPrototypeOf(async function* () {}).constructor);
  add('%AsyncGeneratorPrototype%', () => getPrototypeOf(async function* () {}).prototype);
  add('%AsyncIteratorPrototype%', () => getPrototypeOf(getPrototypeOf(async function* () {}).prototype));
  add('%ThrowTypeError%', () => getOwnPropertyDescriptor(function () { 'use strict'; return arguments; }(), 'callee').get);
  // What only a call hands out, among the web platform APIs and Intl. The
  // realm setup test searches for any that are missing here.
  add('%URLSearchParamsIteratorPrototype%', () => getPrototypeOf(new URLSearchParams().entries()));
  add('%HeadersIteratorPrototype%', () => getPrototypeOf(new Headers().entries()));

  return intrinsics;
}

/**
 * Deletes the accessors of built-ins that aren't prototypes.
 *
 * An accessor on a prototype reads or sets the object it's called on. One
 * on a constructor or a namespace object reads or sets something the
 * engine keeps for the whole realm, which freezing the object doesn't
 * stop: Error.prepareStackTrace on some engines, whose setter installs a
 * hook that runs for every later island, and RegExp.$1 and its siblings
 * on others, which give the last string any island matched. What they
 * give now is no better kept: it's whatever the runner last did.
 *
 * It walks what the globals reach through data properties. Symbol-keyed
 * accessors are left: those are Symbol.species getters, which give the
 * constructor they're called on.
 */
function dropStaticAccessors() {
  const seen = new Set([globalThis]);
  const stack = [];
  const push = (holder, isPrototype) => {
    if (((typeof holder === 'object' && holder !== null) || typeof holder === 'function') && !seen.has(holder)) {
      seen.add(holder);
      stack.push([holder, isPrototype]);
    }
  };
  for (const name of getOwnPropertyNames(globalThis)) {
    push(getOwnPropertyDescriptor(globalThis, name).value, false);
  }
  while (stack.length > 0) {
    const [holder, isPrototype] = stack.pop();
    const descriptors = getOwnPropertyDescriptors(holder);
    for (const key of ownKeys(descriptors)) {
      const descriptor = descriptors[key];
      if ('value' in descriptor) {
        push(descriptor.value, key === 'prototype');
        continue;
      }
      if (!isPrototype && typeof key !== 'symbol' && descriptor.configurable) {
        delete holder[key];
      }
    }
  }
}

/**
 * Makes segment() freeze the prototypes of what it hands out, on first use.
 *
 * Only Intl.Segmenter's segment() hands out its segments, and their
 * iterators, so only a call reaches their prototypes. The first Segmenter
 * a V8 process makes costs about 10 ms, and a Deno process lives for one
 * request. So rather than make one here, segment() freezes both
 * prototypes the first time it's called, before it returns, and no island
 * gets one unfrozen.
 */
function freezeSegmentsOnFirstUse() {
  const prototype = globalThis.Intl?.Segmenter?.prototype;
  const segment = prototype && getOwnPropertyDescriptor(prototype, 'segment')?.value;
  if (typeof segment !== 'function') {
    return;
  }
  let frozen = false;
  // A method, like the built-in: it has the same name and length, and
  // can't be called with new.
  const wrapper = {
    segment(input) {
      const segments = apply(segment, this, [input]);
      if (!frozen) {
        deepFreeze([getPrototypeOf(segments), getPrototypeOf(segments[Symbol.iterator]())]);
        // Set once they're frozen, so a call that throws on the way hands
        // out nothing, and the next one tries again.
        frozen = true;
      }
      return segments;
    },
  };
  defineProperty(prototype, 'segment', { value: wrapper.segment });
}

/**
 * Finds the object a path names.
 *
 * @param {string} path
 *   A global's name or a built-in's %Name%, then property names, separated
 *   by dots.
 * @param {Object<string, object>} intrinsics
 *   The built-ins no global reaches.
 *
 * @returns {object|undefined}
 *   The object, or undefined if the path names none here.
 */
function resolvePath(path, intrinsics) {
  const [first, ...rest] = path.split('.');

  return objectAt(first.startsWith('%') ? intrinsics[first] : globalThis[first], rest);
}

/**
 * Follows own data properties from a value, by name.
 *
 * @param {*} value
 *   Where to start.
 * @param {string[]} names
 *   The property names, in order.
 *
 * @returns {object|undefined}
 *   The object they lead to, or undefined if they lead to none.
 */
function objectAt(value, names) {
  let target = value;
  for (let i = 0; i < names.length && target != null; i++) {
    target = getOwnPropertyDescriptor(target, names[i])?.value;
  }

  return (typeof target === 'object' && target !== null) || typeof target === 'function'
    ? target
    : undefined;
}

/**
 * Tames each property of an object that one of the patterns names.
 *
 * @param {object} target
 *   The object.
 * @param {string[]} patterns
 *   Patterns, as matchingKeys() takes them.
 */
function tameAll(target, patterns) {
  for (const pattern of patterns) {
    for (const key of matchingKeys(target, pattern)) {
      tame(target, key);
    }
  }
}

/**
 * Lists the properties of an object one entry names.
 *
 * @param {object} target
 *   The object.
 * @param {string} pattern
 *   A name, "[Symbol.name]", "prefix*" or "*".
 *
 * @returns {Array<string|symbol>}
 *   Its own properties the pattern matches.
 */
function matchingKeys(target, pattern) {
  if (pattern === '*') {
    return ownKeys(target);
  }
  if (pattern.startsWith('[Symbol.')) {
    const symbol = Symbol[pattern.slice(8, -1)];
    return typeof symbol === 'symbol' ? [symbol] : [];
  }
  if (pattern.endsWith('*')) {
    const prefix = pattern.slice(0, -1);
    return getOwnPropertyNames(target).filter((key) => key.startsWith(prefix));
  }

  return [pattern];
}

/**
 * Turns a data property into an accessor that survives freezing.
 *
 * Assigning to it on the object itself throws, as for any frozen property.
 * Assigning to it on an object that inherits it defines an own property
 * there instead, as it would on an unfrozen prototype.
 *
 * @param {object} target
 *   The object that owns the property.
 * @param {string|symbol} key
 *   The property.
 */
function tame(target, key) {
  const descriptor = getOwnPropertyDescriptor(target, key);
  if (descriptor === undefined || !('value' in descriptor) || !descriptor.configurable) {
    return;
  }
  const { value } = descriptor;
  defineProperty(target, key, {
    get() {
      return value;
    },
    set(newValue) {
      if (this === target) {
        // The target is frozen, so this throws the engine's own error for a
        // write to something frozen, in the engine's own words.
        defineProperty(target, key, { value: newValue });
      }
      defineProperty(this, key, {
        value: newValue,
        writable: true,
        enumerable: true,
        configurable: true,
      });
    },
    enumerable: descriptor.enumerable,
    configurable: false,
  });
}

/**
 * Objects already frozen, or left unfrozen on purpose. Walks stop at them,
 * so freezing a module's exports doesn't walk the built-ins again.
 */
const settled = new WeakSet([globalThis]);

/**
 * Objects left for freezeDeferred(): what preact core's exports reach.
 * preact/compat adds to Component.prototype after preact core evaluates,
 * so they can't be frozen when preact core is. Other walks stop at them.
 */
const deferred = new WeakSet();

/**
 * Where freezeDeferred() starts.
 */
const deferredRoots = [];

/**
 * Exports of chunks that the next library's freezeExports() freezes.
 */
const heldRoots = [];

/**
 * Objects never frozen, though walks pass through them: console, so a
 * component may silence a method, and preact's options, which
 * preact/hooks, preact/compat and the renderer set, and
 * preact-render-to-string sets for every render.
 */
const exempt = new WeakSet();

/**
 * Walks everything the roots reach, through properties, accessors and
 * prototypes, and visits each object once.
 *
 * It stops at settled objects, and at those it's told not to enter.
 *
 * @param {Array<*>} roots
 *   Where to start.
 * @param {function(object): boolean} enter
 *   Whether to visit an object, and walk on through it.
 * @param {function(object): void} visit
 *   What to do with it.
 */
function walk(roots, enter, visit) {
  const seen = new Set();
  const stack = [...roots];
  while (stack.length > 0) {
    const value = stack.pop();
    if (((typeof value !== 'object' || value === null) && typeof value !== 'function')
      || settled.has(value) || seen.has(value) || !enter(value)) {
      continue;
    }
    seen.add(value);
    visit(value);
    stack.push(getPrototypeOf(value));
    const descriptors = getOwnPropertyDescriptors(value);
    for (const key of ownKeys(descriptors)) {
      const descriptor = descriptors[key];
      if ('value' in descriptor) {
        stack.push(descriptor.value);
      }
      else {
        stack.push(descriptor.get, descriptor.set);
      }
    }
  }
}

/**
 * Makes a property non-writable and non-configurable.
 *
 * @param {object} target
 *   The object that owns the property.
 * @param {string|symbol} key
 *   The property.
 */
function lock(target, key) {
  const descriptor = getOwnPropertyDescriptor(target, key);
  if (descriptor.configurable || descriptor.writable) {
    defineProperty(target, key, 'value' in descriptor
      ? { writable: false, configurable: false }
      : { configurable: false });
  }
}

/**
 * Says whether an object is a regular expression.
 *
 * @param {object} value
 *   The object.
 *
 * @returns {boolean}
 *   Whether it is.
 */
function isRegExp(value) {
  if (!hasOwn(value, 'lastIndex')) {
    return false;
  }
  try {
    apply(regExpSource, value, []);
    return true;
  }
  catch {
    return false;
  }
}

/**
 * Freezes and settles an object, unless it's exempt.
 *
 * A typed array that has elements can't be frozen: Object.freeze() throws
 * for one. So it can't gain properties, and its others are locked, but its
 * elements stay writable, like a Map's entries.
 *
 * A regular expression's lastIndex stays writable too, since test() and
 * exec() set it on a /g or /y one. Otherwise a library that exports one
 * couldn't use it.
 *
 * @param {object} value
 *   The object.
 */
function settle(value) {
  settled.add(value);
  if (exempt.has(value)) {
    return;
  }
  if (isRegExp(value)) {
    preventExtensions(value);
    for (const key of ownKeys(value)) {
      if (key !== 'lastIndex') {
        lock(value, key);
      }
    }
    return;
  }
  if (apply(typedArrayTag, value, []) === undefined) {
    freeze(value);
    return;
  }
  try {
    preventExtensions(value);
  }
  catch {
    // One whose length tracks a resizable buffer can't be made so.
  }
  const keys = ownKeys(value);
  // Its elements' keys come first.
  for (let i = apply(typedArrayLength, value, []); i < keys.length; i++) {
    lock(value, keys[i]);
  }
}

/**
 * Freezes everything the roots reach, but deferred and exempt objects.
 *
 * @param {Array<*>} roots
 *   Where to start.
 */
function deepFreeze(roots) {
  walk(roots, (value) => !deferred.has(value), settle);
}

/**
 * Returns a module's exports, from its namespace.
 *
 * @param {object} namespace
 *   The module namespace.
 *
 * @returns {Object<string, *>}
 *   Each export that can be read. In an import cycle, one from a module
 *   that hasn't evaluated yet can't be.
 */
function exportsOf(namespace) {
  const values = {};
  for (const name of getOwnPropertyNames(namespace)) {
    try {
      values[name] = namespace[name];
    }
    catch {
      // Not initialized yet.
    }
  }

  return values;
}

/**
 * Returns the descriptor to define a global, or a console property, with.
 *
 * One that's new, or still configurable, stays configurable, whatever the
 * descriptor says, so it can be deleted or put back before the next
 * island. A runner defines its bindings with its own copy of
 * Object.defineProperty(), which this doesn't change.
 *
 * @param {object} target
 *   The global object or console.
 * @param {string|symbol} key
 *   The property.
 * @param {*} descriptor
 *   The descriptor the caller gave.
 *
 * @returns {*}
 *   The descriptor to use.
 */
function guardedDescriptor(target, key, descriptor) {
  if ((typeof descriptor !== 'object' || descriptor === null) && typeof descriptor !== 'function') {
    // Let the original throw.
    return descriptor;
  }
  const current = getOwnPropertyDescriptor(target, key);
  if (current !== undefined && !current.configurable) {
    return descriptor;
  }
  // A plain copy, with the fields Object.defineProperty() would read.
  const result = { configurable: true };
  for (const field of ['enumerable', 'value', 'writable', 'get', 'set']) {
    if (field in descriptor) {
      result[field] = descriptor[field];
    }
  }

  return result;
}

/**
 * Keeps the global object, console and the exports never frozen extensible,
 * and what islands add to them deletable.
 *
 * Object.preventExtensions(), Object.seal() and Object.freeze() throw for
 * them, and Reflect.preventExtensions() returns false, as for a browser's
 * window. Otherwise an island could stop what it changed being put back,
 * for every island after it, by restoreShared(). Object.defineProperty(),
 * Object.defineProperties() and Reflect.defineProperty() keep a property
 * they add to any of them configurable, so it can be deleted.
 */
function guardGlobalObject() {
  const isGuarded = (object) => object === globalThis
    || (object === consoleObject && object !== undefined)
    || exempt.has(object);
  const refuse = () => {
    throw new TypeError('Cannot prevent extensions on the global object, console or an object SSR keeps extensible');
  };
  const guards = [
    [Object, 'preventExtensions', preventExtensions, refuse],
    [Object, 'seal', Object.seal, refuse],
    [Object, 'freeze', freeze, refuse],
    [Reflect, 'preventExtensions', reflectPreventExtensions, () => false],
  ];
  for (const [target, name, original, onGlobal] of guards) {
    // A method, so it has the original's name and can't be constructed.
    const guarded = {
      [name](object) {
        return isGuarded(object) ? onGlobal() : apply(original, target, [object]);
      },
    }[name];
    defineProperty(target, name, { value: guarded });
  }

  const defineOne = [
    [Object, defineProperty],
    [Reflect, Reflect.defineProperty],
  ];
  for (const [target, original] of defineOne) {
    const guarded = {
      defineProperty(object, key, descriptor) {
        const given = isGuarded(object) ? guardedDescriptor(object, key, descriptor) : descriptor;
        return apply(original, target, [object, key, given]);
      },
    }.defineProperty;
    defineProperty(target, 'defineProperty', { value: guarded });
  }
  const { defineProperties } = Object;
  defineProperty(Object, 'defineProperties', {
    value: {
      defineProperties(object, properties) {
        if (!isGuarded(object) || properties === null || properties === undefined) {
          return apply(defineProperties, Object, [object, properties]);
        }
        const source = Object(properties);
        const given = {};
        for (const key of ownKeys(source)) {
          if (getOwnPropertyDescriptor(source, key)?.enumerable) {
            defineProperty(given, key, {
              value: guardedDescriptor(object, key, source[key]),
              enumerable: true,
              writable: true,
              configurable: true,
            });
          }
        }
        return apply(defineProperties, Object, [object, given]);
      },
    }.defineProperties,
  });
}

/**
 * Holds a chunk's exports for the next library to freeze.
 *
 * A chunk is a provided module no import map entry names, which libraries
 * import by URL. A library re-exports from it, and may mutate what it
 * exports, as preact/compat does preact's. So the library freezes them,
 * once it has evaluated, and tames them first.
 *
 * That holds while only libraries evaluate: in a base, and while
 * evaluateLibraries() runs. At any other time a component may have imported
 * the chunk, by a URL it computed, so its exports are frozen in a promise
 * job queued here. That runs before the job that hands the importer the
 * module, so no component gets them unfrozen. A library that imports the
 * chunk evaluates before any job, and may still mutate them, unless it
 * awaits first.
 *
 * @param {object} namespace
 *   The chunk's namespace.
 */
export function holdExports(namespace) {
  const held = Object.values(exportsOf(namespace));
  heldRoots.push(...held);
  if (componentsRan && !evaluatingLibraries) {
    apply(promiseThen, resolvedPromise, [() => deepFreeze(held)]);
  }
}

/**
 * Freezes the held exports of chunks, before a component's code runs.
 *
 * None are held then, unless a library failed as it evaluated.
 */
function freezeHeld() {
  componentsRan = true;
  if (heldRoots.length > 0) {
    deepFreeze(heldRoots.splice(0));
  }
}

/**
 * Tames a module's configured properties, then deep-freezes its exports,
 * and the held exports of chunks.
 *
 * canvas_ssr appends a call to every module it registers except chunks, to
 * run once the module has evaluated. A module that mutates another's
 * exports later then throws, as for a built-in. The properties to tame are
 * a library's, as an import map entry names it. An object another library
 * exported first is already frozen, and can't be.
 *
 * @param {object} namespace
 *   The module's namespace.
 * @param {Object<string, string[]>} tamed
 *   Properties to tame, by a path from one of its exports, as for
 *   TAMED_GLOBALS. "default" is its default export.
 * @param {string[]|null} deferExempting
 *   For preact core, the exports never to freeze, and freezing the rest is
 *   left to freezeDeferred(). Null for every other module.
 */
export function freezeExports(namespace, tamed, deferExempting) {
  const values = exportsOf(namespace);
  for (const [path, patterns] of Object.entries(tamed)) {
    const [name, ...rest] = path.split('.');
    const target = objectAt(values[name], rest);
    if (target !== undefined) {
      tameAll(target, patterns);
    }
  }

  const roots = Object.values(values);
  if (deferExempting === null) {
    deepFreeze([...roots, ...heldRoots.splice(0)]);
    return;
  }
  for (const name of deferExempting) {
    const value = values[name];
    if ((typeof value === 'object' && value !== null) || typeof value === 'function') {
      exempt.add(value);
      settled.add(value);
    }
  }
  deferredRoots.push(...roots);
  walk(roots, () => true, (value) => {
    deferred.add(value);
  });
}

/**
 * Freezes what freezeExports() deferred, and what's been added to it since.
 *
 * The renderer calls it once preact/compat has added to preact core.
 */
export function freezeDeferred() {
  walk(deferredRoots.splice(0), () => true, settle);
}

const intrinsics = anonymousIntrinsics();

// Deep enough to name a fetch's callers, set while it still can be.
try {
  Error.stackTraceLimit = 50;
}
catch {
  // This engine has none, or has locked it.
}
dropStaticAccessors();
freezeSegmentsOnFirstUse();
guardGlobalObject();
for (const [path, patterns] of Object.entries(TAMED_GLOBALS)) {
  const target = resolvePath(path, intrinsics);
  // Undefined where this engine lacks it, like Temporal on some.
  if (target !== undefined) {
    tameAll(target, patterns);
  }
}

// Canvas's island script declares the page Drupal-rendered before any
// component's module evaluates. Since Canvas 1.12, getSiteData(),
// getPageData() and new JsonApiClient() throw without it. Locked below with
// the other globals.
globalThis.__drupalCanvasRuntime = { environment: 'drupal' };

// The global object's prototype chain is walked too. On Deno, it holds
// addEventListener() and dispatchEvent().
const globalNames = getOwnPropertyNames(globalThis);
const roots = [...Object.values(intrinsics), globalPrototype];
for (const name of globalNames) {
  const descriptor = getOwnPropertyDescriptor(globalThis, name);
  if ('value' in descriptor) {
    roots.push(descriptor.value);
  }
  else {
    roots.push(descriptor.get, descriptor.set);
  }
}
exempt.add(globalThis.console);
deepFreeze(roots);

// Symbol-keyed globals are locked too, but what they hold isn't frozen. On
// Deno, one holds the global object's event listeners, which
// addEventListener() adds to. The Deno runner locks its own listener there.
for (const key of ownKeys(globalThis)) {
  lock(globalThis, key);
}

// The drupalSettings an island reads. An accessor that can't be redefined
// or deleted, so whatever an island does, the next can be given its own.
let drupalSettings;

/**
 * The island rendering, or the last one: what beginIsland() was given,
 * how many modules are evaluating for it, and the drupalSettings they get.
 *
 * @type {object|null}
 */
let island = null;
let rendering = false;

// Starts what's thrown for a read of page data or interface translations
// while a module evaluates during a render. Renderer.php looks for it.
const EARLY_READ = 'SSR early read: ';

// Starts what's thrown when a component's module changes the globals,
// console or what watch() was given as it evaluates. Renderer.php looks for
// it.
const MODULE_SCOPE = 'SSR module scope: ';

// Starts it when something a component's module left running made the
// change. Renderer.php looks for it too.
const MODULE_SCOPE_LATER = `${MODULE_SCOPE}after the island's modules evaluated`;

/**
 * Builds what drupalSettings is while a module evaluates during a render.
 *
 * It's the island's site data, as a module evaluating before the render
 * sees it, with a getter that throws in place of each value the island has
 * that the site data lacks: its page data and interface translations. A
 * module that evaluates during a render could otherwise keep them, and
 * later islands, on other pages or of other graphs, would render them
 * without their cacheability. The reader can't be told from other code in
 * the island that reads while the module awaits, so for that code it
 * throws too: the island falls back to CSR rather than render without its
 * data.
 *
 * Only the objects on the paths to those values are copied, so the site
 * settings stay as they are, for after the island.
 *
 * @param {*} settings
 *   The island's drupalSettings.
 * @param {*} siteSettings
 *   Its site data alone, as drupalSettings.
 *
 * @returns {*}
 *   The drupalSettings to give it.
 */
function evaluationView(settings, siteSettings) {
  const isObject = (value) => typeof value === 'object' && value !== null;
  if (!isObject(settings)) {
    return siteSettings;
  }
  // Returns site, or a copy with getters in place of what it lacks.
  const withheld = (full, site, path) => {
    let view = site;
    for (const key of ownKeys(full)) {
      const keyPath = `${path}.${key}`;
      if (isObject(site) && hasOwn(site, key)) {
        if (!isObject(full[key]) || !isObject(site[key])) {
          continue;
        }
        const inner = withheld(full[key], site[key], keyPath);
        if (inner === site[key]) {
          continue;
        }
        view = view === site ? { ...site } : view;
        view[key] = inner;
        continue;
      }
      view = view === site ? { ...(isObject(site) ? site : {}) } : view;
      defineProperty(view, key, {
        get() {
          throw new Error(`${EARLY_READ}${keyPath} can't be read while a module imported during the render is evaluating, since the module could keep it for other islands. Import the module statically, or read it only while rendering.`);
        },
        enumerable: true,
        configurable: true,
      });
    }

    return view;
  };

  return withheld(settings, siteSettings, 'drupalSettings');
}

defineProperty(globalThis, 'drupalSettings', {
  get() {
    if (rendering && island.depth > 0) {
      if (!island.viewBuilt) {
        island.view = evaluationView(island.settings, island.siteSettings);
        island.viewBuilt = true;
      }
      return island.view;
    }
    return drupalSettings;
  },
  // As in the browser, a component may assign it. The next island gets its
  // own anyway. Assigning what a module evaluating during a render read,
  // as `drupalSettings = drupalSettings || {}` does, changes nothing.
  set(value) {
    if (!(rendering && island.depth > 0 && island.viewBuilt && value === island.view)) {
      drupalSettings = value;
    }
  },
  enumerable: true,
  configurable: false,
});

/**
 * Sets drupalSettings.
 *
 * The drupalSettings module calls this with the site data when it
 * evaluates, and the main module with all of the island's data on every
 * invoke.
 *
 * @param {object|null} settings
 *   The drupalSettings, or null for none, which leaves it undefined.
 */
export function setDrupalSettings(settings) {
  drupalSettings = settings ?? undefined;
}

// The globals that exist now. All are locked, so nothing can change them. A
// runner's bindings are added later, but they're non-configurable, so
// they're left alone too.
const initialGlobals = new Set(ownKeys(globalThis));

// The globals libraries added while they evaluated, each as library
// evaluation last left it. With what watch() is given below, it's the
// record of shared state as libraries left it: every island starts from
// it, and only a library that evaluates changes it.
const libraryGlobals = new Map();

/**
 * Says whether two property descriptors describe the same property.
 *
 * @param {object} a
 *   A descriptor.
 * @param {object} b
 *   Another.
 *
 * @returns {boolean}
 *   Whether they're the same.
 */
function sameProperty(a, b) {
  return a.configurable === b.configurable
    && a.enumerable === b.enumerable
    && a.writable === b.writable
    && Object.is(a.value, b.value)
    && a.get === b.get
    && a.set === b.set;
}

/**
 * Records an object's prototype and own properties.
 *
 * @param {object} object
 *   The object.
 *
 * @returns {Array}
 *   Its prototype and property descriptors.
 */
function saveObject(object) {
  return [getPrototypeOf(object), getOwnPropertyDescriptors(object)];
}

/**
 * Says whether an object differs from what saveObject() recorded.
 *
 * @param {object} object
 *   The object.
 * @param {Array} saved
 *   What saveObject() returned for it.
 *
 * @returns {boolean}
 *   Whether its prototype or any own property differs.
 */
function objectDiffers(object, saved) {
  const [prototype, descriptors] = saved;
  if (getPrototypeOf(object) !== prototype) {
    return true;
  }
  const keys = ownKeys(object);
  if (keys.length !== ownKeys(descriptors).length) {
    return true;
  }
  for (const key of keys) {
    if (!hasOwn(descriptors, key) || !sameProperty(getOwnPropertyDescriptor(object, key), descriptors[key])) {
      return true;
    }
  }

  return false;
}

/**
 * Puts an object back as saveObject() recorded it.
 *
 * A property made non-configurable since, which only a runner's own
 * Object.defineProperty() could, stays.
 *
 * @param {object} object
 *   The object.
 * @param {Array} saved
 *   What saveObject() returned for it.
 */
function restoreObject(object, saved) {
  const [prototype, descriptors] = saved;
  if (getPrototypeOf(object) !== prototype) {
    setPrototypeOf(object, prototype);
  }
  for (const key of ownKeys(object)) {
    if (!hasOwn(descriptors, key) && getOwnPropertyDescriptor(object, key).configurable) {
      delete object[key];
    }
  }
  for (const key of ownKeys(descriptors)) {
    const current = getOwnPropertyDescriptor(object, key);
    if (current === undefined || (current.configurable && !sameProperty(current, descriptors[key]))) {
      defineProperty(object, key, descriptors[key]);
    }
  }
}

/**
 * The objects islands share that aren't frozen, each with the name to call
 * it by and its prototype and properties as libraries left them.
 *
 * @type {Array<{object: object, name: string, saved: Array}>}
 */
const watched = [];

/**
 * Has an object islands share, but that can't be frozen, looked after as
 * the globals are.
 *
 * It's put back as libraries left it before each island, and before a
 * module evaluates outside a render. What a library does to it as it
 * evaluates is kept, and a component's module may not change it then.
 *
 * The framework renderer gives preact's options, which a library may add
 * hooks to as it evaluates, and an island may change while it renders.
 *
 * @param {object} object
 *   The object, as libraries have left it so far.
 * @param {string} name
 *   What to call it when a component's module changes it.
 */
export function watch(object, name) {
  watched.push({ object, name, saved: saveObject(object) });
}

// console isn't frozen, so a component may silence a method.
if (consoleObject !== undefined) {
  watch(consoleObject, 'console');
}

/**
 * Puts shared state back as libraries left it.
 *
 * That's the global object's prototype, which an island can replace though
 * its chain is frozen, the globals, and the watched objects. A global a
 * library added is put back if it was reassigned or deleted. What it
 * holds isn't.
 *
 * @returns {string[]}
 *   What had changed, named for a component's author.
 */
function restoreShared() {
  const names = [];
  if (getPrototypeOf(globalThis) !== globalPrototype) {
    setPrototypeOf(globalThis, globalPrototype);
    names.push("the global object's prototype");
  }
  for (const key of ownKeys(globalThis)) {
    if (!initialGlobals.has(key) && !libraryGlobals.has(key) && getOwnPropertyDescriptor(globalThis, key).configurable) {
      delete globalThis[key];
      names.push(`the global ${String(key)}`);
    }
  }
  for (const [key, saved] of libraryGlobals) {
    const current = getOwnPropertyDescriptor(globalThis, key);
    if (current === undefined || (current.configurable && !sameProperty(current, saved))) {
      defineProperty(globalThis, key, saved);
      names.push(`the global ${String(key)}`);
    }
  }
  for (const entry of watched) {
    if (objectDiffers(entry.object, entry.saved)) {
      restoreObject(entry.object, entry.saved);
      names.push(entry.name);
      // What couldn't be put back is how it is from now on.
      if (objectDiffers(entry.object, entry.saved)) {
        entry.saved = saveObject(entry.object);
      }
    }
  }

  return names;
}

/**
 * Records shared state as it is now, as how libraries left it.
 */
function keepShared() {
  libraryGlobals.clear();
  for (const key of ownKeys(globalThis)) {
    if (!initialGlobals.has(key)) {
      const descriptor = getOwnPropertyDescriptor(globalThis, key);
      if (descriptor.configurable) {
        libraryGlobals.set(key, descriptor);
      }
    }
  }
  for (const entry of watched) {
    entry.saved = saveObject(entry.object);
  }
}

// Whether evaluateLibraries() is running.
let evaluatingLibraries = false;

// Whether a component's code may have run, or may run before the next
// library evaluates: once evaluateLibraries() has finished, a component's
// module has begun evaluating, or an island has begun.
let componentsRan = false;

// Whether a library, and whether a component's module, has begun evaluating
// outside a render since the last island ended.
let librariesEvaluated = false;
let componentsEvaluated = false;

/**
 * What beginEvaluation() returned for each component's module still
 * evaluating outside a render.
 *
 * Usually there's at most one, and only until its own code ends. One that
 * awaits stays while other modules evaluate, and one that throws stays
 * until the next island.
 */
const openComponents = new Set();

// What beginIsland() throws: what a component's module left running
// changed, once no module was evaluating to throw it.
let leftRunning = null;

/**
 * Builds what's thrown for what a component's module changed.
 *
 * @param {string[]} names
 *   What it changed.
 * @param {boolean} later
 *   Whether something it left running changed it, after it evaluated.
 *
 * @returns {Error}
 *   The error.
 */
function refusal(names, later = false) {
  const what = later
    ? `${MODULE_SCOPE_LATER}, something they left running changed ${names.join(', ')}`
    : `${MODULE_SCOPE}a component's module changed ${names.join(', ')} as it evaluated`;

  return new Error(`${what}. On the server that would reach every later island, whatever its component, so it's refused. Make the change while rendering instead.`);
}

/**
 * Settles who changed shared state since it was last settled.
 *
 * It's called whenever a module begins evaluating outside a render, and
 * when an island begins. It goes by what has evaluated since the last
 * island ended:
 *
 * - Only libraries: the changes are theirs, and kept. That's so while
 *   evaluateLibraries() runs too, whatever else has.
 * - A component's module: a component's module may not change shared
 *   state as it evaluates, so the changes are undone and refused. Every
 *   component's module still evaluating throws once its own code ends. If
 *   none is, something one left running made them, and the island throws
 *   when it begins.
 * - Nothing: an earlier island made the changes while it rendered, or left
 *   something running that did. They're undone.
 */
function settleShared() {
  if (evaluatingLibraries || (librariesEvaluated && !componentsEvaluated)) {
    keepShared();
    return;
  }
  const names = restoreShared();
  if (names.length === 0 || !componentsEvaluated) {
    return;
  }
  if (openComponents.size === 0) {
    leftRunning ??= refusal(names, true);
    return;
  }
  for (const open of openComponents) {
    open.error ??= refusal(names);
  }
}

/**
 * Forgets what has evaluated, for the next island.
 */
function forgetEvaluated() {
  openComponents.clear();
  librariesEvaluated = false;
  componentsEvaluated = false;
  leftRunning = null;
}

/**
 * Evaluates libraries one after another, before any component that imports
 * them evaluates.
 *
 * A library evaluates only once, so what it changes as it evaluates is
 * kept for every later island, and a component's module may change
 * nothing then. Evaluating an island's libraries first, each on its own,
 * keeps the two apart, as a base does. Otherwise a library would evaluate
 * among the component's modules, and one that awaits would let them run
 * meanwhile.
 *
 * One that fails is left for the module that imports it to fail on.
 *
 * @param {Array<function(): Promise>} imports
 *   Functions that each import a library.
 */
export async function evaluateLibraries(imports) {
  // Nothing is evaluating now. What a library left running is kept, and
  // what the modules of an invoke that failed did is dropped, with whom to
  // hold it against.
  settleShared();
  forgetEvaluated();
  evaluatingLibraries = true;
  try {
    for (const importLibrary of imports) {
      try {
        await importLibrary();
      }
      catch {
        // Its importer reports it.
      }
    }
  }
  finally {
    evaluatingLibraries = false;
    freezeHeld();
  }
}

/**
 * Called by the statement put before every other module's own code.
 *
 * Outside a render, it first settles who changed shared state, so the
 * module starts from what libraries left, and not from what an earlier
 * island did. drupalSettings gives the module only site data then.
 *
 * During a render, as for a module imported with import(), drupalSettings
 * gives it only the island's site data until endEvaluation(). Calls don't
 * nest, but with top-level await they interleave: a module that awaits
 * lets others evaluate meanwhile. Hence a count, not a stack. A module
 * that throws never calls endEvaluation(), so the count stays up until the
 * island ends.
 *
 * @param {boolean} component
 *   Whether it's a component's module.
 *
 * @returns {object}
 *   For endEvaluation(): whether it's a component's module, the island
 *   rendering, if one is, and for a library, the globals that exist.
 */
export function beginEvaluation(component = false) {
  const token = { component, island: rendering ? island : null, keys: null, error: null };
  if (component) {
    freezeHeld();
  }
  if (rendering) {
    island.depth++;
    if (!component) {
      token.keys = new Set(ownKeys(globalThis));
    }
    return token;
  }
  settleShared();
  if (component) {
    componentsEvaluated = true;
    openComponents.add(token);
  }
  else {
    librariesEvaluated = true;
  }

  return token;
}

/**
 * Called by lines appended to every other module, after its own code.
 *
 * Outside a render, what a library changed is kept. A component's module
 * may have changed nothing: in the browser it evaluates only on a page
 * it's on, but here what it did would reach every later island in the
 * realm, whatever its component. What it changed is put back, and it
 * throws, so every island importing it falls back to CSR. It may make
 * those changes while it renders.
 *
 * A module that began evaluating during a render keeps only the globals a
 * library added: the island may have changed the rest too. That goes by
 * when it began, since a module that awaits can end after the island has.
 * What a component's module changed then is the render's, and undone
 * before the next island.
 *
 * @param {object} token
 *   What beginEvaluation() returned.
 */
export function endEvaluation(token) {
  if (token.island !== null) {
    if (token.island.depth > 0) {
      token.island.depth--;
    }
    if (!token.component) {
      for (const key of ownKeys(globalThis)) {
        if (!initialGlobals.has(key) && !token.keys.has(key)) {
          const descriptor = getOwnPropertyDescriptor(globalThis, key);
          if (descriptor.configurable) {
            libraryGlobals.set(key, descriptor);
          }
        }
      }
    }
    return;
  }
  if (!token.component) {
    keepShared();
    return;
  }
  if (!openComponents.delete(token)) {
    // What islands did since can't be told from what the module did.
    throw new Error(`${MODULE_SCOPE}a component's module was still evaluating when an island began or ended, so what it changed as it evaluated can't be checked.`);
  }
  const names = restoreShared();
  const error = token.error ?? (names.length > 0 ? refusal(names) : null);
  if (error !== null) {
    throw error;
  }
}

/**
 * Readies the realm for an island. The main module calls it first.
 *
 * It throws if something a component's module left running changed shared
 * state after the module evaluated, as the module itself would have.
 *
 * @param {object|null} settings
 *   The island's drupalSettings.
 * @param {object|null} siteSettings
 *   Its site data alone, as drupalSettings.
 */
export function beginIsland(settings, siteSettings = settings) {
  freezeHeld();
  settleShared();
  const error = leftRunning;
  forgetEvaluated();
  setDrupalSettings(settings);
  island = {
    settings,
    siteSettings: siteSettings ?? undefined,
    depth: 0,
    view: undefined,
    viewBuilt: false,
  };
  rendering = true;
  if (error !== null) {
    throw error;
  }
}

// The import() calls of components' modules that haven't settled.
const pendingImports = new Set();

// Whether one was made since that was last asked.
let importMade = false;

/**
 * Called by a component's module with each import() it makes.
 *
 * A module the component doesn't wait for may load after the island has
 * rendered: a runner may only finish a load on a later turn of its loop,
 * once the job queue is empty and the invoke is over. What the component
 * does with it then would happen during a later island, and count as that
 * island's. So the island isn't over until these have settled.
 *
 * @param {Promise} promise
 *   What import() returned.
 *
 * @returns {Promise}
 *   The same promise.
 *
 * @throws {TypeError}
 *   When it isn't a promise.
 *
 * @see \Drupal\canvas_ssr\ComponentInfo\ComponentModules::rewrite()
 */
export function trackImport(promise) {
  const settled = () => {
    pendingImports.delete(promise);
  };
  // This throws for anything but a promise, which a component can call
  // this with. Waiting for such a thing would call its then(), and it
  // would never be taken off the list.
  apply(promiseThen, promise, [settled, settled]);
  importMade = true;
  pendingImports.add(promise);

  return promise;
}

/**
 * Tells whether a component's import() may still have something to do.
 *
 * The main module asks once the island has rendered. If so, it leaves the
 * island open, for Renderer to invoke this module until it's settled.
 *
 * @returns {boolean}
 *   Whether one was made since this was last asked, or hasn't settled.
 */
export function importsUnsettled() {
  const made = importMade;
  importMade = false;

  return made || pendingImports.size > 0;
}

/**
 * Waits for the import() calls components' modules made. Renderer invokes
 * this module for it, so it's the default export.
 *
 * A runner runs the job queue dry before an invoke ends. So when none was
 * made since the last invoke ended, and none is pending, nothing is left
 * that could make one, and the island ends. Otherwise what follows the
 * pending ones may make more, which the next invoke finds.
 *
 * @returns {Promise<string>}
 *   "more" if Renderer should invoke it again, or "" once the island has
 *   ended. An invoke returns a string.
 */
export default async function settleImports() {
  if (!importsUnsettled()) {
    endIsland();
    return '';
  }
  for (const promise of [...pendingImports]) {
    try {
      await promise;
    }
    catch {
      // The component's to handle.
    }
  }

  return 'more';
}

/**
 * Called by the main module once the island has rendered, or failed to.
 *
 * drupalSettings goes back to the island's site data, as before a render,
 * for a module that evaluates or resumes before the next island.
 */
export function endIsland() {
  forgetEvaluated();
  rendering = false;
  setDrupalSettings(island.siteSettings);
}
