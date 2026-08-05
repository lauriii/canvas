import type { PageHead } from '@drupal-canvas/headless/server';
import type { Metadata } from 'next';

/**
 * Converts the supported Canvas head matrix to Next.js Metadata.
 *
 * Unknown `name` tags retain their HTML semantics through Metadata.other.
 * Unknown `property` tags are omitted because Metadata.other would render them
 * as `name` tags and silently change their meaning.
 */
export function toNextMetadata(head: PageHead): Metadata {
  const metadata: Metadata = {
    title: { absolute: head.title },
  };
  const other: NonNullable<Metadata['other']> = {};
  const openGraph: Record<string, unknown> = {};
  const twitter: Record<string, unknown> = {};

  for (const meta of head.meta ?? []) {
    if (meta.name && meta.content !== undefined) {
      const name = meta.name.toLowerCase();
      if (name === 'description') {
        metadata.description = meta.content;
      } else if (name === 'keywords') {
        metadata.keywords = meta.content;
      } else if (name === 'application-name') {
        metadata.applicationName = meta.content;
      } else if (name === 'generator') {
        metadata.generator = meta.content;
      } else if (name === 'referrer') {
        metadata.referrer = meta.content as Metadata['referrer'];
      } else if (name.startsWith('twitter:')) {
        addSocialValue(twitter, name.slice('twitter:'.length), meta.content);
      } else {
        appendOther(other, meta.name, meta.content);
      }
    }
    if (meta.property && meta.content !== undefined) {
      const property = meta.property.toLowerCase();
      if (property.startsWith('og:')) {
        addSocialValue(openGraph, property.slice('og:'.length), meta.content);
      }
    }
  }

  const canonical = head.link?.find((link) => link.rel === 'canonical');
  const languages: Record<string, string | Array<{ url: string }>> = {};
  const icons: string[] = [];
  const apple: string[] = [];
  let shortcut: string | undefined;
  for (const link of head.link ?? []) {
    const rel = link.rel.toLowerCase();
    if (rel === 'alternate' && link.hreflang) {
      const existing = languages[link.hreflang];
      languages[link.hreflang] = existing
        ? Array.isArray(existing)
          ? [...existing, { url: link.href }]
          : [{ url: existing }, { url: link.href }]
        : link.href;
    } else if (rel === 'icon') {
      icons.push(link.href);
    } else if (rel === 'shortcut icon') {
      shortcut = link.href;
    } else if (rel === 'apple-touch-icon') {
      apple.push(link.href);
    } else if (rel === 'manifest') {
      metadata.manifest = link.href;
    }
  }
  if (canonical || Object.keys(languages).length > 0) {
    metadata.alternates = {
      ...(canonical ? { canonical: canonical.href } : {}),
      ...(Object.keys(languages).length > 0
        ? {
            languages: languages as NonNullable<
              NonNullable<Metadata['alternates']>['languages']
            >,
          }
        : {}),
    };
  }
  if (icons.length > 0 || shortcut || apple.length > 0) {
    metadata.icons = {
      ...(icons.length > 0 ? { icon: icons } : {}),
      ...(shortcut ? { shortcut } : {}),
      ...(apple.length > 0 ? { apple } : {}),
    };
  }
  if (Object.keys(openGraph).length > 0) {
    metadata.openGraph = openGraph as Metadata['openGraph'];
  }
  if (Object.keys(twitter).length > 0) {
    metadata.twitter = twitter as Metadata['twitter'];
  }
  if (Object.keys(other).length > 0) {
    metadata.other = other;
  }
  return metadata;
}

/**
 * Adds a recognized Open Graph or Twitter field.
 */
function addSocialValue(
  target: Record<string, unknown>,
  name: string,
  value: string,
): void {
  const key = {
    site_name: 'siteName',
    image: 'images',
    'image:url': 'images',
    video: 'videos',
    'video:url': 'videos',
    audio: 'audio',
  }[name];
  if (key === undefined) {
    if (
      [
        'title',
        'description',
        'url',
        'type',
        'locale',
        'card',
        'site',
        'creator',
      ].includes(name)
    ) {
      target[name] = value;
    }
    return;
  }
  const values = target[key];
  target[key] = Array.isArray(values) ? [...values, value] : [value];
}

/**
 * Preserves repeated named metadata through Metadata.other.
 */
function appendOther(
  other: NonNullable<Metadata['other']>,
  name: string,
  value: string,
): void {
  const existing = other[name];
  other[name] =
    existing === undefined
      ? value
      : Array.isArray(existing)
        ? [...existing, value]
        : [existing, value];
}
