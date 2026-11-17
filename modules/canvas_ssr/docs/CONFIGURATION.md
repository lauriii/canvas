# Configuration

canvas_ssr has no configuration form. A site changes how it renders through
container parameters, which it sets in its `services.yml`. Rebuild the
container (`drush cr`) after changing one.

## Time limits

`canvas_ssr.timeouts` sets SSR's three time limits, in milliseconds. See
`\Drupal\canvas_ssr\Timeouts` for what each one limits, and the [Deno runner's
README](../src/JsRunner/Deno/README.md#time-limits) for how that runner
applies them. A site may set any of them, and those it leaves out get their
defaults. Each must be at least twice the one before it: medium twice short,
and long twice medium.

```yaml
parameters:
  canvas_ssr.timeouts:
    short: 100
    medium: 2000
    long: 10000
```

## Tamed properties

The [realm setup module](GLOSSARY.md#realm-setup-module) freezes every
built-in. Freezing makes assigning to a property throw, even on an object that
only inherits it, so `error.name = 'Custom'` would throw, since
`Error.prototype.name` is frozen. A [tamed property](GLOSSARY.md#tamed-property)
becomes an accessor whose setter gives the object assigned to an own property
instead, and throws only for the frozen object itself.

`canvas_ssr.tamed_properties` lists the defaults, in canvas_ssr.services.yml.
A site adds entries to `canvas_ssr.additional_tamed_properties`, which is
merged in, so it keeps the defaults as they change:

```yaml
parameters:
  canvas_ssr.additional_tamed_properties:
    globals:
      Map.prototype: [set]
      '%%IteratorPrototype%%': [map]
    modules:
      some-library:
        Widget.prototype: [render]
```

Under `globals`, each key is a path to an object: a global's name, or a
built-in only syntax reaches, by its SES name like `%IteratorPrototype%`, then
property names. In `services.yml`, `%` is written `%%`, since the container
reads `%name%` as another parameter. Each value lists the object's properties:
a name, a well-known symbol like `'[Symbol.iterator]'`, `'prefix*'` for every
name with the prefix, or `'*'` for every own property.

Under `modules`, each key is a specifier, resolved through the SSR import map
as from the page, and each value maps a path from one of the module's exports,
`default` for its default export, to properties as above. Name the library
that first exports the object: one that re-exports another library's export
finds it already frozen.

An entry that isn't in this format is skipped, with a warning in the log.

The defaults are SES's ["moderate"
enablements](https://github.com/endojs/endo/blob/master/packages/ses/src/enablements.js),
plus what Canvas's components need:

- the error prototypes' properties, which error subclasses set;
- `Iterator.prototype[Symbol.iterator]`, which TypeScript's generator helper
  sets;
- `Date.prototype`'s getters and setters, which date-fns/tz's `TZDate`
  overrides.
