# Using React components inside Twig in the Canvas Stark theme

In the rest of this document, `Drupal Canvas` will be referred to as `Canvas`.

## Finding issues 🐛, code 🤖 & people 👯‍♀️
Related Canvas issue queue components:
- [Redux-integrated field widgets](https://www.drupal.org/project/issues/canvas?component=Redux-integrated+field+widgets)

## Overview
### 1. Include React Components in Twig
React components can be included in Twig via custom HTML elements such as `<drupal-canvas-form>` or `<drupal-canvas-input>`.

To make a custom HTML element render a component, you need to:
- Go to the Twig-to-component map definition [here](modules/contrib/canvas/ui/src/components/form/twig-to-jsx-component-map.js).
- Import the React component you want to use.
- Update `twigToJSXComponentMap` so the key is the custom HTML element name and the value is the imported React component.

For example, adding `'drupal-canvas-textarea': DrupalTextArea` means that `<drupal-canvas-textarea>` in Twig will render the `DrupalTextArea` React component.

#### 1.1 Custom element naming convention
The custom element name should be in the format `drupal-canvas-[component-name]` or `canvas-[component-name]`.
Adhering to this convention ensures that attributes are properly converted into formats that work well in React.
There is no functional difference between the two, but `drupal-canvas-[component-name]` is typically used for components
originating from Drupal render arrays, while `canvas-[component-name]` is typically used for components that originate
in Twig, such as the `<canvas-text>` which is used to render its contents in a Radix <Text> component.
```twig

### 2. Map props from Twig to React
Props can be passed from Twig to React components in two ways:
  - Via HTML attributes (the most common scenario)
  - Via slots (for when the prop should arrive as an element)
#### 2.1 Via attributes
This is very similar to how you would pass props to a React component in JSX, with a few additional considerations:
- Kebab-case and snake_case attribute names will be converted to camelCase prop names in the React component. So `title-display` or `title_display` in Twig will arrive as `titleDisplay` in React.
- For values that are not yet strings but will be (such as `MarkupInterface` or an array with a `#markup` key that do not include actual markup), you can use the `render_var` filter to ensure the string value is sent to React.
- If the value is an element (includes HTML that should be parsed), do not send it as an attribute—use a slot instead (see next section).

```twig
<drupal-canvas-custom-element
    {# Attributes will arrive in React as an object with {attributeName: attributeValue} pairs. #}
    attributes="{{ attributes }}"
    {# An options array will arrive in React as an array. #}
    options="{{ options }}"
    {# Arrives as the titleDisplay prop in React. #}
    title-display="{{ title_display }}"
    {# Use render_var to convert items like MarkupInterface or arrays with #markup to strings. #}
    title="{{ render_var(title) }}"
    {# Arrives as the titleAttributes prop in React. #}
    title_attributes="{{ title_attributes }}"
>
</drupal-canvas-custom-element>
```

#### 2.2 Via slots
If a prop is to receive an element (such as a label or description, which are often strings but can contain markup such as `<em>`), it should be passed via a slot. This is because attributes can't have elements as values. The slot name will ultimately be the prop name in the React component.

Use the `slot` function in Twig to pass the element as a slot. The first argument is the slot name, and the second argument is the value (which can be a string or an element).

For example, this component passes the label as an element to a `label` prop:

```twig
<drupal-canvas-foobar-element
    attributes="{{ attributes }}"
    {# etc... #}
>
  {{ children }}
  {% if label %}
      {# The label will arrive as the foobarLabel prop in React, and it will be an element. #}
      {{ slot('foobar_label', label) }}
  {% endif %}
</drupal-canvas-foobar-element>
```

### 3. Hyperscriptify
Please note this is a *very* broad overview of a complex process.

[Hyperscriptify](https://github.com/effulgentsia/hyperscriptify) is the library that converts the markup that renders the custom elements mentioned above as React components. It automatically maps attributes to React-safe names, unserializes JSON strings, and passes elements in slots as props with element values.

There is a local copy of Hyperscriptify in Canvas at `modules/contrib/canvas/ui/src/utils/hyperscriptify.js` instead of in `node_modules` so we can make modifications as needed—these will ultimately be contributed back to the main project.

```javascript
const twigToJSXComponentMap = {
  'drupal-canvas-form': Form, // Form is a React component.
  'drupal-canvas-input': Input, // Input is a React component.
  'drupal-canvas-link': Link, // Link is a React component.
};

hyperscriptify(
  document.querySelector('template[data-hyperscriptify]').content,
  React.createElement,
  React.Fragment,
  twigToJSXComponentMap,
  { propsify },
)
```
