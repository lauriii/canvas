## Why is this directory full of .html.twig files that contain JSON?

- These are for arrays rendered by the semi_coupled theme engine instead of
  twig.
- Keeping the .html.twig extension makes it easier for twig and semi coupled
  templates to comfortably exist in the same render array, and even send Twig
  rendered markup to be used within a React component.
- The JSON maps properties of the render array to React component Prop Types
