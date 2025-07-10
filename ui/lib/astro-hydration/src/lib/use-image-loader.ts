// @todo Remove this file in https://drupal.org/i/3532718.
import type { ImageLoaderParams } from 'next-image-standalone';

export function useImageLoader(srcSetCandidateTemplate: string) {
  // Note: The loader function in next/image only accepts the parameters `src`,
  // `width`, and `quality`. `next-image-standalone` also passes the `imageProps`
  // parameter. (This is expressed in the `ImageLoaderParams` type.)
  return ({ width, imageProps }: ImageLoaderParams) => {
    let result = srcSetCandidateTemplate;

    if (result.includes('{width}')) {
      result = result.replace('{width}', width.toString());
    }

    if (result.includes('{height}')) {
      // A loader would normally not need to deal with a height, because the
      // service returning the resized image would calculate it based on the
      // image's intrinsic aspect ratio. However, our example placeholder
      // images are loaded from https://placehold.co, where we need to provide a
      // height. We calculate it for the resized image using the aspect ratio of
      // the original image.
      const { width: intrinsicWidth, height: intrinsicHeight } = imageProps;
      const height = Math.round(width / (intrinsicWidth / intrinsicHeight));
      result = result.replace('{height}', height.toString());
    }

    return result;
  };
}
