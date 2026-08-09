/**
 * @file
 *
 * Definition to derive a type of a prop based on its schema.
 *
 * This is used to produce a distinct set of types to be presented on the UI
 * when defining props for a code component.
 *
 * E.g. the serialized prop schema could have the following shape:
 * @code
 * {
 *   "type": "string",
 *   "format": "uri",
 * }
 * @endcode
 *
 * Which would be derived as the "link" type. The same shape without the
 * "format" property would be derived as the "text" type.
 *
 * @see config/schema/canvas.schema.yml#canvas.js_component.*.mapping.props
 */

import type { CodeComponentPropSerialized } from '@/types/CodeComponent';

const derivedPropTypes = [
  {
    type: 'text' as const,
    displayName: Drupal.t('Text', {}, { context: 'Canvas code component' }),
    derive: (prop: CodeComponentPropSerialized) =>
      prop.type === 'string' &&
      !prop.$ref &&
      !prop.format &&
      !prop.contentMediaType &&
      !prop['x-formatting-context'] &&
      (!prop.enum || prop.enum.length === 0),
    init: {
      type: 'string',
    },
  },
  {
    type: 'formattedText' as const,
    displayName: Drupal.t(
      'Formatted text',
      {},
      { context: 'Canvas code component' },
    ),
    derive: (prop: CodeComponentPropSerialized) =>
      prop.type === 'string' &&
      prop.contentMediaType === 'text/html' &&
      prop['x-formatting-context'] === 'block',
    init: {
      type: 'string',
      contentMediaType: 'text/html',
      'x-formatting-context': 'block',
    },
  },
  {
    type: 'link' as const,
    displayName: Drupal.t('Link', {}, { context: 'Canvas code component' }),
    derive: (prop: CodeComponentPropSerialized) =>
      prop.type === 'string' &&
      ['uri', 'uri-reference'].includes(prop.format as string),
    init: {
      type: 'string',
      format: 'uri-reference',
    },
  },
  {
    type: 'image' as const,
    displayName: Drupal.t('Image', {}, { context: 'Canvas code component' }),
    derive: (prop: CodeComponentPropSerialized) =>
      prop.type === 'object' && prop.$ref?.includes('image'),
    init: {
      type: 'object',
      $ref: 'json-schema-definitions://canvas.module/image',
      example: {
        src: 'https://placehold.co/800x600@2x.png?alternateWidths=https%3A%2F%2Fplacehold.co%2F%7Bwidth%7Dx%7Bheight%7D%402x.png',
        width: 800,
        height: 600,
        alt: 'Example image placeholder',
      },
    },
  },
  {
    type: 'video' as const,
    displayName: Drupal.t('Video', {}, { context: 'Canvas code component' }),
    derive: (prop: CodeComponentPropSerialized) =>
      prop.type === 'object' && prop.$ref?.includes('video'),
    init: {
      type: 'object',
      $ref: 'json-schema-definitions://canvas.module/video',
      example: {
        src: '/ui/assets/videos/mountain_wide.mp4',
        poster: 'https://placehold.co/1920x1080.png?text=Widescreen',
      },
    },
  },
  {
    type: 'date' as const,
    displayName: Drupal.t(
      'Date and time',
      {},
      { context: 'Canvas code component' },
    ),
    derive: (prop: CodeComponentPropSerialized) =>
      prop.type === 'string' &&
      ['date', 'date-time'].includes(prop.format as string),
    init: {
      type: 'string',
      format: 'date',
    },
  },
  {
    type: 'boolean' as const,
    displayName: Drupal.t('Boolean', {}, { context: 'Canvas code component' }),
    derive: (prop: CodeComponentPropSerialized) => prop.type === 'boolean',
    init: {
      type: 'boolean',
      example: false,
    },
  },
  {
    type: 'integer' as const,
    displayName: Drupal.t('Integer', {}, { context: 'Canvas code component' }),
    derive: (prop: CodeComponentPropSerialized) =>
      prop.type === 'integer' && (!prop.enum || prop.enum.length === 0),
    init: {
      type: 'integer',
    },
  },
  {
    type: 'number' as const,
    displayName: Drupal.t('Number', {}, { context: 'Canvas code component' }),
    derive: (prop: CodeComponentPropSerialized) =>
      prop.type === 'number' && (!prop.enum || prop.enum.length === 0),
    init: {
      type: 'number',
    },
  },
  {
    type: 'listText' as const,
    displayName: Drupal.t(
      'List: text',
      {},
      { context: 'Canvas code component' },
    ),
    derive: (prop: CodeComponentPropSerialized) =>
      prop.type === 'string' && prop.enum && prop.enum.length > 0,
    init: {
      type: 'string',
      enum: [],
    },
  },
  {
    type: 'listInteger' as const,
    displayName: Drupal.t(
      'List: integer',
      {},
      { context: 'Canvas code component' },
    ),
    derive: (prop: CodeComponentPropSerialized) =>
      prop.type === 'integer' && prop.enum && prop.enum.length > 0,
    init: {
      type: 'integer',
      enum: [],
    },
  },
  {
    type: 'contentEntityReference' as const,
    displayName: Drupal.t(
      'Content entity reference',
      {},
      { context: 'Canvas code component' },
    ),
    derive: (prop: CodeComponentPropSerialized) =>
      prop.type === 'object' &&
      prop.$ref ===
        'json-schema-definitions://canvas.module/content-entity-reference',
    init: {
      type: 'object',
      $ref: 'json-schema-definitions://canvas.module/content-entity-reference',
    },
  },
];

export default derivedPropTypes;
