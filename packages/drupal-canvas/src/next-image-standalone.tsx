import NextImage from 'next-image-standalone';

import type { ImageLoaderParams, ImageProps } from 'next-image-standalone';

export default function Image(
  props: Omit<ImageProps, 'loader'> & {
    // `next-image-standalone` expects a loader function, but we make that
    // optional as long as an `src` prop is provided with a `alternateWidths`
    // query string parameter, in which case we'll provide a default loader.
    loader?: (params: ImageLoaderParams) => string;
  },
) {
  const { src, loader } = props;

  // `src` is a URL string in this integration; a statically imported image
  // arrives as an object carrying its URL.
  const srcString =
    typeof src === 'string'
      ? src
      : 'default' in src
        ? src.default.src
        : src.src;

  if (!loader) {
    // Example `src` value:
    // /sites/default/files/2025-07/maple-street.jpg?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--{width}/public/2025-07/maple-street.jpg.webp?itok=…
    const alternateWidths = new URLSearchParams(
      srcString.split('?')[1]?.split('#')[0],
    ).get('alternateWidths');

    // Drupal generates no derivative images for this image (an SVG image, for
    // example): render it as-is. `unoptimized` omits `srcset` and `sizes`.
    // ⚠️ `next-image-standalone` does detect `.svg` itself, but only when using
    // Next.js' own image optimizer; with a custom loader it assumes the loader
    // knows what it is doing. It requires a loader even when it never calls it.
    if (!alternateWidths) {
      return <NextImage {...props} loader={() => srcString} unoptimized />;
    }

    const defaultLoader = ({ width, imageProps }: ImageLoaderParams) => {
      let result = alternateWidths.replace('{width}', width.toString());

      if (result.includes('{height}')) {
        // This loader only needs to deal with the height when the example
        // image is loaded from https://placehold.co, in which case adding a
        // height in the URL is required. As a workaround, the code editor adds
        // "{height}" as part of the `alternateWidths` query string parameter,
        // so we can do the replacement here.
        // `next/image` only passes the width to the loader, but
        // `next-standalone-image` also exposes an `imageProps` parameter,
        // which gives us access to the intrinsic image dimensions.
        // Based on those we can also calculate the appropriate height for the
        // resized placeholder image.
        // The dimension props admit numeric strings and may be absent.
        const intrinsicWidth = Number(imageProps.width);
        const intrinsicHeight = Number(imageProps.height);
        if (!intrinsicWidth || !intrinsicHeight) {
          console.error(
            'Height calculation requires intrinsic image dimensions.',
            { src },
          );
          // Fallback to the original `src`.
          return srcString;
        }
        const height = Math.round(width / (intrinsicWidth / intrinsicHeight));
        result = result.replace('{height}', height.toString());
      }

      return result;
    };
    return (
      <NextImage
        {...props}
        loader={defaultLoader}
        sizes={props.sizes || 'auto 100vw'}
      />
    );
  }
  return (
    <NextImage {...props} loader={loader} sizes={props.sizes || 'auto 100vw'} />
  );
}
