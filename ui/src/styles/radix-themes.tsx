/**
 * @file Radix Themes CSS.
 * @see https://www.radix-ui.com/themes/docs
 *
 * Here we import the Radix Themes CSS and make it available to the
 * application. See `ui/src/main.tsx`, where we also wrap everything in the
 * `<Theme>` component.
 *
 * A simple way to import all CSS from Radix Themes would be to import
 * `@radix-ui/themes/styles.css`. Instead, we pick and choose which colors are
 * included, reducing the CSS bundle size. (By default, color definitions take
 * up 20% of the total CSS.)
 * @see https://www.radix-ui.com/themes/docs/theme/color#individual-css-files
 */

// Base theme tokens
import '@radix-ui/themes/tokens/base.css';

// Colors

// Add more colors as needed.
// @see https://www.radix-ui.com/colors
// @see https://github.com/radix-ui/colors/blob/main/src/light.ts

import '@radix-ui/themes/tokens/colors/gray.css';
import '@radix-ui/themes/tokens/colors/slate.css';
import '@radix-ui/themes/tokens/colors/blue.css';
import '@radix-ui/themes/tokens/colors/purple.css';
import '@radix-ui/themes/tokens/colors/sand.css';
import '@radix-ui/themes/tokens/colors/red.css';

// Components and utilities
import '@radix-ui/themes/components.css';
import '@radix-ui/themes/utilities.css';
