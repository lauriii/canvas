# Drupal Canvas: a gentle introduction for contributors

Drupal Canvas is amazing. It can also be hard to understand. It's incredibly complicated, entirely unique, and in many ways a departure from Drupal's traditional way of doing things. But there's an excellent reason for the complexity: it allows things to be as simple and seamless as possible for end users!

This document is meant to be an easygoing introduction to Canvas's internals, concepts, and workings. It's not complete, but hopefully it will give you a better sense of how Canvas wants to work so that you'll feel more confident about digging into it as a contributor. I won't get too far into the weeds, but I'll try to offer a good overview from the proverbial 30,000 feet. 🪂

So without further ado, here are a few big-picture points to help you make sense of Canvas.

## 1. Canvas is visual-first
Canvas treats a page exactly opposite from the way that Drupal historically does. What we're used to in Drupal is: start with some data, then lay it out and format it in some way. Data comes first.

Canvas works in the opposite direction. _First_, you make a layout (an arrangement of components) then you wire data into that layout, selectively. There are a few different ways to do that; more on this in a bit.

## 2. Under the hood, layouts are surprisingly simple and elegant
Every layout in Canvas, no matter how intricate, is contained in a new type of field called a "component tree".

A component tree is a simple, ordered list. Every item in the list is a component instance. Each component instance contains all the information needed to render that component:

* What kind of component it is — an SDC, block, code component, etc.
* Which slot it's in (if it's sitting in a slot defined by another component)
* The "inputs" for the component — that is, what values its "props" (if it's an SDC or code component) or settings (if it's a block) should have.
* Additional internal metadata

Every part of the layout is a component, which should be a familiar concept to front-end developers. Components can have (or not) props and slots. Components' props can be populated in different ways, including by pulling values from various places in Drupal.

## 3. There are different ways to wire data into a layout
Canvas needs to translate Drupal data, which has its own set of assumptions and idiosyncrasies, into front-end component props, which have no awareness or understanding of how Drupal works (and rightfully so). There are a few different ways to do it, but the two most common are:

* **Static props** have values that are stored with the layout, and don't change dynamically. They're not tied to Drupal fields. Well, sort of; they actually do use Drupal's field _types_ under the hood, but that's some implementation magic that isn't important right now. But the important thing to understand is that static props don't deal with the kind of regular, persistent fields like you'd see on a Drupal content editing form.
* **Dynamic props** get pulled in from an entity — for example, the node you're looking at, or the current user, or some other data structure — dynamically, during rendering and previewing (and editing). These values aren't stored in the layout; the layout just stores instructions ("prop expressions", as they're known) for where to get the values from. Conceptually, these are very similar to the tokens that Drupal users are familiar with; the difference is tokens can only be strings, but component props can take on different shapes (integers, arrays of booleans, specialized kinds of strings such as URLs, and so forth).

Canvas refers to these different methods of pulling data into the layout as "prop sources", so you'll see terms like "static prop source" and "dynamic prop source" quite a bit. But to be clear, a component prop is not _inherently_ static or dynamic — these are just different ways for a prop to get a value. In the future, Canvas might add more prop sources; for example, to fetch data from remote APIs.

It's worth noting that not all components accept Drupal data. Blocks are a good example: they have settings, but not props that you can wire Drupal data into.

## 4. "Shapes" keep you sane when you want to map fields to props
One of the pain points of core's Layout Builder module is that, when you want to put a field block into a layout, it'll let you choose _any_ field you've got, even ones that are meaningless to the layout you're working on. This isn't a great experience for anyone.

In Canvas, let's say you're editing a component that has a prop which expects a URL (maybe it's a link button component or similar). If you want to wire that prop to some Drupal data, it's completely pointless to offer you, say, an image field as something you can map to that prop. But even if you only offered link fields, not all link fields will make sense for that prop — perhaps the prop only accepts internal URLs, rather than any old URL. In a situation like that, it would really be best to only show link fields that _only_ store internal URLs. An even clearer example: if a component needs a URL for rendering an image, it's important that it only receives image URLs, and not a URL to some random web page, video, or torrent file.

Canvas calls this a "prop shape". Every prop in a component has a "shape" — that is, the kind of data it accepts and what that data has to look like. Canvas does a ton of work to infer the shape of all of a component's props, _and_ the shape of all Drupal fields, so it can ensure that it always matches props to an appropriate field. This "shape matching" is complicated, but it's a crucial part of Canvas's usefulness. Shape matching gets into the deepest, darkest parts of how Drupal handles data modeling and validation, so if you're going to work on this, it's very useful to have some familiarity with core's Typed Data API and Symfony-based validation system.
