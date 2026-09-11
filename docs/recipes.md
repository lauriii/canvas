# Canvas, Recipes, and Life Aboard the Pain Train

Steel yourself, brave soul, because this one is weird and complicated.

## Typographic Conventions
- `Component(s)` = a `canvas.component.*` entity (or several).
- component = the thing that actually powers a `Component`, such as a block, SDC, or code component.

## Background
In a sentence: Canvas and recipes don't play nicely together.

It's not because Canvas is bad, or that recipes are poorly designed. They each have a different set of assumptions and needs, and those give rise to conflicts that cause bugs. Ever thus in software engineering, right?

### What Canvas does
`Components` are foundational. Without them, Canvas is useless.  At the same time, Canvas understandably doesn't want to make site builders manually create `Components`, with a tedious form, every time they want to add a component to their library.

So Canvas takes the approach of _automatically generating_ (and updating) `Components` at certain times. A `Component` is, in essence, a history of "snapshots" of a component at certain times, in the context of a specific site. Canvas tries to auto-generate (or update) `Components` as soon as possible. Examples:

- If a module provides SDCs or blocks, Canvas wants to create the necessary `Components` as soon as that module is installed.
- Or if such a module is _updated_ in a way that changes the components it provides, Canvas tries to update the relevant `Components` right away.
- If a field is created or changed in a way that will affect how certain components might use it, Canvas tries to silently and automatically update the relevant `Components`.

In other words, Canvas tries to treat `Components` as something an end user shouldn't need to think much about. If all is going well, they're in the background, and they're up-to-date snapshots of the components that are actually sitting in the code base. (Without going into the weeds, that history is critically important for Canvas to store component trees efficiently, run update paths, and more.)

They're also stored _in configuration_. `Components` are configuration entities. Like all other configuration in Drupal, they can be exported and synced across environments.

So the crucial takeaway here is: **`Components` are configuration that is created and updated _as a side effect_ of something else that happens in the site.**

### What recipes do
By their nature, recipes expect **complete control** over configuration.

Here's an example. If you install a module normally, you'll get that module's config (which might also include some subset of its optional config). But if a recipe installs a module, you get _none_ of its config by default — apart from simple config, like basic one-off settings, that aren't relevant here. The recipe is in control of _exactly_ what config is created.

It's a neat trick, and recipes achieve it by putting Drupal into config sync mode whilst installing modules and themes. When syncing, Drupal takes a far more "hands-off" approach to configuration: it just trusts that the incoming config is exactly what we want.

Once modules and themes are installed, recipes _exit_ sync mode and import the contents of their `config` directory.

If you did a double-take there, then you've been paying attention. Because yes, this is how recipes work:

- While installing modules and themes, a recipe puts the system in config sync mode.
- But it is NOT syncing while importing the contents of its `config` directory.

This _was_ a core bug, but [it was fixed](https://www.drupal.org/project/drupal/issues/3613607). Once Canvas requires a core version that includes the fix, some of the pain below goes away — but not all of it, because The Conflict (see below) will still exist.

Recipes do this because they are supposed to be free of side effects. The goal of a recipe is to be _totally_ predictable: it does _exactly_ what it says it will do. Nothing more, nothing less.

### The conflict
See the problem? Here it is:

- Canvas wants to create and update `Components` — which are configuration — when it needs to, as a result of _something else_ happening in the site.
- Recipes expect temporary, but complete, control over all configuration in order to _avoid_ side effects (which they hate).

It's like two ships colliding in the night.

This hurts site templates the most because they are recipes which, in many cases, _use Canvas as their page builder_ and include fully themed design systems, layouts, and demo content that uses components (and, therefore, `Components`)!

### How this manifests
When things are going badly with `Components`, you'll usually see errors like this:

```
'a917d5c9be8f2830' is not a version that exists on component config entity 'sdc.mercury.section'. Available versions: '3f0c8e1b5d2a7c4e'.
```

This is a maddening error, because the version is a deterministic hash that looks meaningless to the uninitiated.

Remember: when a component changes, or the site changes _around it_ in certain ways, the relevant `Component` entity will be automatically updated by Canvas, and a new version of it will be created. Or at least that's what supposed to happen.

But it won't happen while a recipe is importing config! Remember: **recipes expect to be in complete control of configuration**. Canvas tries to accommodate that by _silently skipping `Component` generation and updating_ while the recipe is writing config. More specifically:

1. It will skip component generation (and updating) _while importing a recipe's `config` directory_. (It literally looks for `installRecipeConfig` in the call stack. Yes, really.)
2. It will also skip that while installing a module or theme during config sync mode (which, remember, is _on_ when a recipe is installing modules and themes).

So Canvas tries, valiantly, to give the recipe the complete control that it wants.

And guess what? _Most of the time it works!_

### Where it breaks
This arrangement falls apart if you have a situation where _you **need** to know a `Component`'s version_, but you also _**cannot** predict or control it_.

Consider a site template that:

- Uses Canvas as its page builder
- Includes configuration that uses `Components` (such as content templates)
- Applies _another_, foundational recipe first, which installs Canvas

What's going to happen here?

1. That foundational recipe is applied. It installs Canvas.
2. Canvas, after applying a recipe, generates all the `Components` that it can. Their version hash is going to depend on what other modules are installed (and in what versions), what fields are set up and how, and other factors.
3. The site template is applied.
4. It tries to import its content templates (or whatever other config is using `Components`).
5. That config is referencing specific versions of `Components`.
6. Those versions _had better_ exist, or Canvas will throw an exception, right in the middle of applying the recipe. (Or, if you're installing Drupal for the first time, it won't throw...but the pages that use components will be broken when you look at them.)
7. You are now sad.

## Prior Art :(
**What if we remove the `Component` versions from the content templates (or whatever)? It works for `canvas_page` content!**

If you do this, Canvas freaks out when the recipe tries to validate the configuration it has imported.

**Can't the recipe just include `config/canvas.component.*.yml`?**

Sure it can, but let's say (for example) that the foundational recipe already caused the `block.system_branding_block` `Component` to be created. What happens to `config/canvas.component.block.system_branding_block.yml` now? It depends on what `config.strict` says:

1. `strict: true` (the default): core compares the recipe's `config` against active config — but it does that when the recipe is _loaded_, before anything is actually changed. When doing a fresh install of Drupal, the `Component` doesn't exist yet, so the check passes. If you apply the recipe to a site that already _has_ that `Component`, `strict: true` will throw an exception if it's not identical.
2. `strict: false` (which is what Byte does): nothing gets compared at all.

This is how recipes work. Their `config` directory doesn't touch, or overwrite, existing config. (You use config actions for that.) So whatever component versions it contains, aren't going to be recognized.

**Okay, why don't we just update the `Component` versions in the content templates (or whatever)?**

Because we can't necessarily predict them. `Component` versions can vary based on several factors, including things like the exact version of core and which field types are available. Setting the `Component` versions manually would be extremely fragile.

## The Status Quo
If you have a recipe that is going to ship Canvas configuration that uses `Components` (content templates, page variants, and patterns), you'd better also have ironclad control of the underlying components.

In some cases, that's exactly the situation you find yourself in. For example, if you've got a site template where you're using a bespoke theme that you maintain in tandem with the recipe (all Drupal CMS-maintained site templates do this; `byte` and `byte_theme` are an example), then `byte` can ship `Components` for `byte_theme` because it can pretty much _guarantee_ their versions (the theme and the recipe are tightly coupled). Nothing except Byte itself (or its theme) is going to be in play, so the `Components` for the SDCs provided by `byte_theme` _can_ be included in Byte's config. And they are!

Code components are another example: they're derived from config (`canvas.js_component.*.yml`) that would _also_ (presumably) be part of the recipe. The recipe controls both the components _and_ the `Components`, so it can nearly guarantee the resulting versions.

## The current workaround
We can't always control the `Component` versions.

Yet we still need to use them in Canvas configuration.

The current solution — a workaround, really — is to **use config actions**.

Config actions sit fully outside the `config` directory and import workflows explained above. When config actions run, a recipe is still applying...but _config is not syncing_.

If you want to have Canvas configuration that uses `Components` whose version you cannot control, use the `setComponentTree` config action from Drupal CMS Helper: https://git.drupalcode.org/project/drupal_cms/-/blob/2.x/recipes/drupal_cms_starter/recipe.yml?ref_type=heads#L32

It lets you set up a layout, but also _omit_ `Component` versions where you need to. It regenerates all `Components` first, then fills in the current version wherever you left one out. The idea is that, as long as input shapes match the way the system is set up, Canvas will be happy with it.

Two rules: use it _last_ (after anything else in the recipe that could change a version), and use it _sparingly_ (if you control every component in the tree, ship plain config with explicit versions instead).

And if Canvas isn't happy with it, you'll know. Config actions use validation too, so if you try set up an invalid layout that uses bad versions or inputs, you're going to get an exception. But rightfully so!
