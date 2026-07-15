import { fidsCodec, makeFileUploadWidget } from './FileWidget';

import type { ClientWidgetDefinition } from '../types';

/**
 * Native counterpart of `image_image`.
 *
 * Shares the upload-first single-file UI and the fids codec with the file
 * widget; the differences are presentation (a thumbnail preview of the
 * uploaded image) and the file input's accept filter.
 */
export const imageImageWidget: ClientWidgetDefinition = {
  component: makeFileUploadWidget('image'),
  codec: fidsCodec,
  // v1 supports one image per prop; multi-cardinality image props keep the
  // server-built widget's add/remove UX via the escape hatch.
};
