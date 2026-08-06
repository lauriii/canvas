import { serializeJsonForHtml } from '@drupal-canvas/headless/server';

import type { PageHead } from '@drupal-canvas/headless/server';
import type { AnyRouteMatch } from '@tanstack/react-router';

/** The result accepted by a TanStack route's head callback. */
export type TanStackHead = {
  meta: NonNullable<AnyRouteMatch['meta']>;
  links: NonNullable<AnyRouteMatch['links']>;
  scripts: NonNullable<AnyRouteMatch['headScripts']>;
};

type TanStackLink = NonNullable<TanStackHead['links'][number]>;
type TanStackCrossOrigin = NonNullable<TanStackLink['crossOrigin']>;
type TanStackFetchPriority = NonNullable<TanStackLink['fetchPriority']>;
type TanStackReferrerPolicy = NonNullable<TanStackLink['referrerPolicy']>;

function isTanStackCrossOrigin(value: string): value is TanStackCrossOrigin {
  return value === '' || value === 'anonymous' || value === 'use-credentials';
}

function isTanStackFetchPriority(
  value: string,
): value is TanStackFetchPriority {
  return value === 'high' || value === 'low' || value === 'auto';
}

function isTanStackReferrerPolicy(
  value: string,
): value is TanStackReferrerPolicy {
  return [
    '',
    'no-referrer',
    'no-referrer-when-downgrade',
    'origin',
    'origin-when-cross-origin',
    'same-origin',
    'strict-origin',
    'strict-origin-when-cross-origin',
    'unsafe-url',
  ].includes(value);
}

/**
 * Converts a Canvas head to TanStack Router's route-head shape.
 */
export function toTanStackHead(head: PageHead): TanStackHead {
  return {
    meta: [
      { title: head.title },
      ...(head.meta ?? []).map(
        ({ charset, 'http-equiv': httpEquiv, ...meta }) => ({
          ...meta,
          ...(charset !== undefined ? { charSet: charset } : {}),
          ...(httpEquiv !== undefined ? { httpEquiv } : {}),
        }),
      ),
    ],
    links: (head.link ?? []).map(
      ({
        charset,
        crossorigin,
        fetchpriority,
        hreflang,
        imagesizes,
        imagesrcset,
        referrerpolicy,
        ...link
      }) => ({
        ...link,
        ...(charset !== undefined ? { charSet: charset } : {}),
        ...(crossorigin !== undefined && isTanStackCrossOrigin(crossorigin)
          ? { crossOrigin: crossorigin }
          : {}),
        ...(fetchpriority !== undefined &&
        isTanStackFetchPriority(fetchpriority)
          ? { fetchPriority: fetchpriority }
          : {}),
        ...(hreflang !== undefined ? { hrefLang: hreflang } : {}),
        ...(imagesizes !== undefined ? { imageSizes: imagesizes } : {}),
        ...(imagesrcset !== undefined ? { imageSrcSet: imagesrcset } : {}),
        ...(referrerpolicy !== undefined &&
        isTanStackReferrerPolicy(referrerpolicy)
          ? { referrerPolicy: referrerpolicy }
          : {}),
      }),
    ),
    scripts: (head.script ?? []).map((script) => ({
      type: script.type,
      children: serializeJsonForHtml(script.textContent),
    })),
  };
}
