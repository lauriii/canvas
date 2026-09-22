/**
 * @file
 * Tests for yaml-comments.ts
 */

import { describe, expect, it } from 'vitest';

import { dumpMetadataWithComments } from './yaml-comments';

import type { ColorFolderEntry } from '../types/Component';
import type { Metadata } from '../types/Metadata';

describe('dumpMetadataWithComments', () => {
  const mockFolders: ColorFolderEntry[] = [
    {
      id: '88888888-8888-4888-8888-888888888888',
      name: 'Pastel Lab Revelation',
      type: 'color',
      weight: 0,
      items: [],
    },
    {
      id: '99999999-9999-4999-9999-999999999999',
      name: 'Absolute Neon Casserole',
      type: 'color',
      weight: 0,
      items: [],
    },
  ];

  it('should add inline comments on folder UUIDs', () => {
    const metadata: Metadata = {
      name: 'Test',
      machineName: 'test',
      status: true,
      required: [],
      props: {
        properties: {
          backgroundColor: {
            type: 'string',
            title: 'Background Color',
            $ref: 'json-schema-definitions://canvas.module/color',
            'x-canvas-color-picker': 'kit-only',
            'x-canvas-color-folders': ['88888888-8888-4888-8888-888888888888'],
          },
        },
      },
      slots: {},
      dataDependencies: {},
    };

    const result = dumpMetadataWithComments(metadata, mockFolders);

    expect(result).toContain('# Pastel Lab Revelation');
  });

  it('should add commentBefore on x-canvas-color-folders key', () => {
    const metadata: Metadata = {
      name: 'Test',
      machineName: 'test',
      status: true,
      required: [],
      props: {
        properties: {
          backgroundColor: {
            type: 'string',
            title: 'Background Color',
            $ref: 'json-schema-definitions://canvas.module/color',
            'x-canvas-color-picker': 'kit-only',
            'x-canvas-color-folders': ['88888888-8888-4888-8888-888888888888'],
          },
        },
      },
      slots: {},
      dataDependencies: {},
    };

    const result = dumpMetadataWithComments(metadata, mockFolders);

    expect(result).toContain('# Any color value is accepted on push');
    expect(result).toContain('restricted to: Pastel Lab Revelation');
    expect(result).toContain('To add a folder, see available color folders');
  });

  it('should add footer comment when component has restricted props', () => {
    const metadata: Metadata = {
      name: 'Test',
      machineName: 'test',
      status: true,
      required: [],
      props: {
        properties: {
          backgroundColor: {
            type: 'string',
            title: 'Background Color',
            $ref: 'json-schema-definitions://canvas.module/color',
            'x-canvas-color-picker': 'kit-only',
            'x-canvas-color-folders': ['88888888-8888-4888-8888-888888888888'],
          },
        },
      },
      slots: {},
      dataDependencies: {},
    };

    const result = dumpMetadataWithComments(metadata, mockFolders);

    expect(result).toContain('Available color folders');
    expect(result).toContain(
      '88888888-8888-4888-8888-888888888888  Pastel Lab Revelation',
    );
    expect(result).toContain(
      '99999999-9999-4999-9999-999999999999  Absolute Neon Casserole',
    );
  });

  it('should not add footer when component has no restricted props', () => {
    const metadata: Metadata = {
      name: 'Test',
      machineName: 'test',
      status: true,
      required: [],
      props: {
        properties: {
          backgroundColor: {
            type: 'string',
            title: 'Background Color',
            $ref: 'json-schema-definitions://canvas.module/color',
          },
        },
      },
      slots: {},
      dataDependencies: {},
    };

    const result = dumpMetadataWithComments(metadata, mockFolders);

    expect(result).not.toContain('Available color folders');
  });

  it('should add free-pick note for kit-and-free props', () => {
    const metadata: Metadata = {
      name: 'Test',
      machineName: 'test',
      status: true,
      required: [],
      props: {
        properties: {
          backgroundColor: {
            type: 'string',
            title: 'Background Color',
            $ref: 'json-schema-definitions://canvas.module/color',
            'x-canvas-color-picker': 'kit-and-free',
            'x-canvas-color-folders': ['88888888-8888-4888-8888-888888888888'],
          },
        },
      },
      slots: {},
      dataDependencies: {},
    };

    const result = dumpMetadataWithComments(metadata, mockFolders);

    expect(result).toContain('Free-pick is also allowed in the UI');
  });

  it('should handle multiple folders', () => {
    const metadata: Metadata = {
      name: 'Test',
      machineName: 'test',
      status: true,
      required: [],
      props: {
        properties: {
          backgroundColor: {
            type: 'string',
            title: 'Background Color',
            $ref: 'json-schema-definitions://canvas.module/color',
            'x-canvas-color-picker': 'kit-only',
            'x-canvas-color-folders': [
              '88888888-8888-4888-8888-888888888888',
              '99999999-9999-4999-9999-999999999999',
            ],
          },
        },
      },
      slots: {},
      dataDependencies: {},
    };

    const result = dumpMetadataWithComments(metadata, mockFolders);

    expect(result).toContain(
      'restricted to: Pastel Lab Revelation, Absolute Neon Casserole',
    );
  });

  it('should not add inline comment for unknown folder UUID', () => {
    const metadata: Metadata = {
      name: 'Test',
      machineName: 'test',
      status: true,
      required: [],
      props: {
        properties: {
          backgroundColor: {
            type: 'string',
            title: 'Background Color',
            $ref: 'json-schema-definitions://canvas.module/color',
            'x-canvas-color-picker': 'kit-only',
            'x-canvas-color-folders': ['77777777-7777-4777-b777-777777777777'],
          },
        },
      },
      slots: {},
      dataDependencies: {},
    };

    const result = dumpMetadataWithComments(metadata, mockFolders);

    // Should still have the key comment but not the inline comment
    expect(result).toContain('# Any color value is accepted on push');
    // The UUID should appear without an inline comment
    expect(result).toContain('77777777-7777-4777-b777-777777777777');
  });

  it('should single-quote hex color strings in examples', () => {
    const metadata: Metadata = {
      name: 'Test',
      machineName: 'test',
      status: true,
      required: [],
      props: {
        properties: {
          backgroundColor: {
            type: 'string',
            title: 'Background Color',
            $ref: 'json-schema-definitions://canvas.module/color',
            examples: ['#aabbcc'],
          },
        },
      },
      slots: {},
      dataDependencies: {},
    };

    const result = dumpMetadataWithComments(metadata, []);

    expect(result).toContain("'#aabbcc'");
  });
});
