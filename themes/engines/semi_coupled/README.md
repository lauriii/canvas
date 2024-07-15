# What does the Semi Coupled theme engine do?
 
## A very incomplete overview
- Any template that doesn't have a filename ending in `--xbxb` is 
  processed in the usual Twig manner.
- When a template that ends-with-`--xbxb` is found, the render array is  
  restructured to be output as a custom element, fragment and all of it wrapped in a
 `<template>` - invisible to the user.
- The template contents are then rendered by React. The `--xbxb` Twig templates 
  map attributes in the custom elements to React props in the React Components
  that ultimately render them
- Render elements without `--xbxb` are turned into Markup by Twig, then rendered
  as child elements inside the React app. Twig and JSX coexist quite peacefully.
- See `experience_builder_theme_suggestions_alter()`.
