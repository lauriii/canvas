// The directive below must survive any future compiled build of this
// package: the provider holds React context, so it is client code that server
// components can render with serializable translations.
'use client';

import { TranslationsProvider as Provider } from 'drupal-canvas/react';

import type { TranslationsProviderProps } from 'drupal-canvas/react';

/**
 * Replaces the interface translations of the Code Components below it with
 * the application's own dictionary, instead of the translations Drupal
 * returns in `page.context`. Render it above `CanvasComponentTree`.
 *
 * @see TranslationsProvider in drupal-canvas/react
 */
export function TranslationsProvider(props: TranslationsProviderProps) {
  return <Provider {...props} />;
}

export type { TranslationsProviderProps };
