import { useRef, useState } from 'react';
import { Cross2Icon, FileIcon, UploadIcon } from '@radix-ui/react-icons';
import { Button, Flex, IconButton, Text } from '@radix-ui/themes';

import { formatEndpointError, uploadFile } from './mediaEndpoints';

import type {
  ClientWidgetContext,
  ClientWidgetDefinition,
  ClientWidgetProps,
  WidgetCodec,
} from '../types';

/**
 * The internal value of the native file/image widgets: the uploaded file's
 * id plus enough presentation data for the closed-state display.
 */
export interface UploadedFileValue {
  fid: number;
  url: string;
  filename: string;
  filesize: number | null;
  width: number | null;
  height: number | null;
}

/**
 * Extracts a file id from a stored fids value. The persisted shape is a
 * single-element array like `[12]` (what `mainProperty(name: 'fids')`
 * produces for single cardinality); scalars and `{fids}` records are
 * tolerated for robustness.
 */
const extractFid = (value: unknown): number | null => {
  if (typeof value === 'number') {
    return value;
  }
  if (typeof value === 'string' && value !== '') {
    const numeric = Number(value);
    return Number.isNaN(numeric) ? null : numeric;
  }
  if (Array.isArray(value)) {
    return value.length > 0 ? extractFid(value[0]) : null;
  }
  if (value !== null && typeof value === 'object' && 'fids' in value) {
    return extractFid((value as { fids: unknown }).fids);
  }
  return null;
};

const firstRecord = (value: unknown): Record<string, unknown> | null => {
  const record = Array.isArray(value) ? value[0] : value;
  return record !== null && typeof record === 'object'
    ? (record as Record<string, unknown>)
    : null;
};

const numberOrNull = (value: unknown): number | null =>
  typeof value === 'number' && !Number.isNaN(value) ? value : null;

/**
 * The codec shared by `file_generic` and `image_image`.
 *
 * Parity with the server-widget path's `mainProperty(name: 'fids')`
 * transform: the persisted value is the Drupal widget's fids value — a
 * single-element array like `[12]` — stored as both source and
 * (pre-evaluation) resolved value. The authoritative server evaluation echo
 * replaces resolved with the file/image object for the preview.
 */
export const fidsCodec: WidgetCodec = {
  toModel(widgetValue) {
    const file = widgetValue as UploadedFileValue | null | undefined;
    if (!file || file.fid === null || file.fid === undefined) {
      return null;
    }
    const fids = [file.fid];
    return { resolved: fids, source: fids };
  },
  fromModel(sourceValue, resolvedValue) {
    const fid = extractFid(sourceValue);
    if (fid === null) {
      return null;
    }
    // The resolved value may already be the server-evaluated image/file
    // object; reuse whatever presentation data it offers.
    const resolved = firstRecord(resolvedValue);
    const url =
      typeof resolved?.src === 'string'
        ? resolved.src
        : typeof resolved?.url === 'string'
          ? resolved.url
          : '';
    const filename =
      typeof resolved?.filename === 'string'
        ? resolved.filename
        : (url.split('/').pop()?.split('?')[0] ?? '');
    return {
      fid,
      url,
      filename,
      filesize: numberOrNull(resolved?.filesize),
      width: numberOrNull(resolved?.width),
      height: numberOrNull(resolved?.height),
    } satisfies UploadedFileValue;
  },
};

const formatFileSize = (bytes: number | null): string | null => {
  if (bytes === null) {
    return null;
  }
  if (bytes < 1024) {
    return `${bytes} bytes`;
  }
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`;
  }
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

/**
 * Builds the file input's `accept` attribute from the field instance's
 * allowed extensions, falling back to any image for the image variant.
 */
const getAccept = (
  context: ClientWidgetContext,
  variant: 'image' | 'file',
): string | undefined => {
  const instance = context.sourceTypeSettings.instance as
    | { file_extensions?: string }
    | undefined;
  const extensions = instance?.file_extensions?.trim();
  if (extensions) {
    return extensions
      .split(/\s+/)
      .map((extension) => `.${extension}`)
      .join(',');
  }
  return variant === 'image' ? 'image/*' : undefined;
};

const thumbStyle: React.CSSProperties = {
  width: '40px',
  height: '40px',
  objectFit: 'cover',
  borderRadius: 'var(--radius-2)',
  background: 'var(--gray-3)',
  flexShrink: 0,
};

const nameStyle: React.CSSProperties = {
  flexGrow: 1,
  overflow: 'hidden',
  textOverflow: 'ellipsis',
  whiteSpace: 'nowrap',
};

/**
 * Upload-first single-file widget shared by `file_generic` and
 * `image_image` (parity with Drupal's file/image widgets: no browsing of
 * existing files). Shows an upload button when empty; the uploaded file's
 * thumbnail (image variant) or filename and size plus a remove button
 * otherwise.
 */
export const makeFileUploadWidget = (
  variant: 'image' | 'file',
): React.FC<ClientWidgetProps> => {
  const FileUploadWidget = (props: ClientWidgetProps) => {
    const {
      value,
      onChange,
      disabled,
      inputId,
      componentId,
      componentVersion,
      propName,
    } = props;
    const file = (value as UploadedFileValue | null | undefined) ?? null;
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const handleFileChange = async (
      event: React.ChangeEvent<HTMLInputElement>,
    ) => {
      const selected = event.target.files?.[0];
      // Reset the input so re-selecting the same file re-fires the event.
      event.target.value = '';
      if (!selected) {
        return;
      }
      setUploading(true);
      setUploadError(null);
      try {
        const response = await uploadFile(selected, {
          component: componentId,
          version: componentVersion,
          prop: propName,
        });
        onChange({
          fid: response.fid,
          url: response.url,
          filename: response.filename,
          filesize: response.filesize,
          width: response.width,
          height: response.height,
        } satisfies UploadedFileValue);
      } catch (error) {
        setUploadError(formatEndpointError(error));
      } finally {
        setUploading(false);
      }
    };

    const sizeText =
      variant === 'file' ? formatFileSize(file?.filesize ?? null) : null;

    return (
      <Flex direction="column" gap="2" align="start">
        {file ? (
          <Flex align="center" gap="2" width="100%">
            {variant === 'image' && file.url ? (
              <img src={file.url} alt={file.filename} style={thumbStyle} />
            ) : (
              <FileIcon style={{ flexShrink: 0 }} />
            )}
            <Text size="1" style={nameStyle} title={file.filename}>
              {file.filename || `File ${file.fid}`}
              {sizeText ? ` (${sizeText})` : ''}
            </Text>
            <IconButton
              size="1"
              variant="ghost"
              color="gray"
              aria-label={`Remove ${file.filename || 'file'}`}
              disabled={disabled || uploading}
              onClick={() => {
                setUploadError(null);
                onChange(null);
              }}
            >
              <Cross2Icon />
            </IconButton>
          </Flex>
        ) : (
          <>
            <Button
              id={inputId}
              size="1"
              variant="soft"
              disabled={disabled}
              loading={uploading}
              onClick={() => fileInputRef.current?.click()}
            >
              <UploadIcon />{' '}
              {variant === 'image' ? 'Upload image' : 'Upload file'}
            </Button>
            <input
              ref={fileInputRef}
              type="file"
              hidden
              accept={getAccept(props, variant)}
              onChange={handleFileChange}
              data-testid={`canvas-${variant}-upload-input`}
            />
          </>
        )}
        {uploadError && (
          <Text size="1" color="red">
            {uploadError}
          </Text>
        )}
      </Flex>
    );
  };
  FileUploadWidget.displayName = `FileUploadWidget(${variant})`;
  return FileUploadWidget;
};

export const fileGenericWidget: ClientWidgetDefinition = {
  component: makeFileUploadWidget('file'),
  codec: fidsCodec,
  // v1 supports one file per prop; multi-cardinality file props keep the
  // server-built widget's add/remove UX via the escape hatch.
};
