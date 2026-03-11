import { beforeEach, describe, expect, it, vi } from 'vitest';

const { parseFontFileMock, fontState } = vi.hoisted(() => ({
  parseFontFileMock: vi.fn(),
  fontState: {
    current: {
      names: {
        preferredFamily: {
          en: 'Mona Sans',
        },
      },
      tables: {
        fvar: undefined,
        head: undefined,
        os2: undefined,
        post: undefined,
      },
    } as {
      names: {
        preferredFamily?: Record<string, string>;
        fontFamily?: Record<string, string>;
        preferredSubfamily?: Record<string, string>;
        fontSubfamily?: Record<string, string>;
        fullName?: Record<string, string>;
      };
      tables: {
        fvar:
          | {
              axes: Array<{
                tag: string;
                minValue: number;
                defaultValue: number;
                maxValue: number;
                name?: Record<string, string>;
              }>;
            }
          | undefined;
        head:
          | {
              macStyle?: number;
            }
          | undefined;
        os2:
          | {
              fsSelection?: number;
              usWeightClass?: number;
            }
          | undefined;
        post:
          | {
              italicAngle?: number;
            }
          | undefined;
      };
    },
  },
}));

const loadFontMetadataModule = () => import('./fontMetadata');

describe('fontMetadata helpers', () => {
  beforeEach(() => {
    vi.resetModules();
    parseFontFileMock.mockClear();
    vi.doMock('./fontParser', () => ({
      parseFontFile: parseFontFileMock.mockImplementation(
        async () => fontState.current,
      ),
    }));
    fontState.current = {
      names: {
        preferredFamily: {
          en: 'Mona Sans',
        },
      },
      tables: {
        fvar: undefined,
        head: undefined,
        os2: undefined,
        post: undefined,
      },
    };
  });

  const createMockFile = (name: string): File =>
    ({
      name,
      arrayBuffer: vi.fn().mockResolvedValue(new ArrayBuffer(8)),
    }) as unknown as File;

  it('falls back to a readable family from the filename', async () => {
    const { fallbackFontFamilyFromFilename } = await loadFontMetadataModule();

    expect(fallbackFontFamilyFromFilename('mona-sans_variable.woff2')).toBe(
      'Mona Sans Variable',
    );
  });

  it('reads the embedded font family from metadata', async () => {
    const { readFontFamilyFromFile } = await loadFontMetadataModule();
    const family = await readFontFamilyFromFile(
      createMockFile('mona-sans.ttf'),
    );

    expect(family).toBe('Mona Sans');
    expect(parseFontFileMock).toHaveBeenCalledOnce();
  });

  it('detects italic static fonts from font metadata', async () => {
    const { readFontMetadataFromFile } = await loadFontMetadataModule();
    fontState.current = {
      names: {
        preferredFamily: {
          en: 'Mona Sans',
        },
        preferredSubfamily: {
          en: 'Italic',
        },
      },
      tables: {
        fvar: undefined,
        head: undefined,
        os2: {
          fsSelection: 0,
        },
        post: {
          italicAngle: 0,
        },
      },
    };

    const metadata = await readFontMetadataFromFile(
      createMockFile('mona-sans-italic.ttf'),
    );

    expect(metadata?.style).toBe('italic');
    expect(metadata?.variantType).toBe('static');
  });

  it('reads static font weight from os/2 metadata', async () => {
    const { readFontMetadataFromFile } = await loadFontMetadataModule();
    fontState.current = {
      names: {
        preferredFamily: {
          en: 'Mona Sans',
        },
      },
      tables: {
        fvar: undefined,
        head: undefined,
        os2: {
          usWeightClass: 700,
        },
        post: undefined,
      },
    };

    const metadata = await readFontMetadataFromFile(
      createMockFile('mona-sans-bold.ttf'),
    );

    expect(metadata?.weight).toBe('700');
    expect(metadata?.variantType).toBe('static');
  });

  it('falls back to subfamily names for static font weight', async () => {
    const { readFontMetadataFromFile } = await loadFontMetadataModule();
    fontState.current = {
      names: {
        preferredFamily: {
          en: 'Mona Sans',
        },
        preferredSubfamily: {
          en: 'SemiBold',
        },
      },
      tables: {
        fvar: undefined,
        head: undefined,
        os2: undefined,
        post: undefined,
      },
    };

    const metadata = await readFontMetadataFromFile(
      createMockFile('mona-sans-semibold.ttf'),
    );

    expect(metadata?.weight).toBe('600');
  });

  it('extracts variable font axes and defaults from metadata', async () => {
    const { readFontMetadataFromFile } = await loadFontMetadataModule();
    fontState.current = {
      names: {
        preferredFamily: {
          en: 'Inter',
        },
      },
      tables: {
        fvar: {
          axes: [
            {
              tag: 'wght',
              minValue: 100,
              defaultValue: 400,
              maxValue: 900,
              name: { en: 'Weight' },
            },
            {
              tag: 'wdth',
              minValue: 75,
              defaultValue: 100,
              maxValue: 125,
              name: { en: 'Width' },
            },
          ],
        },
        head: undefined,
        os2: undefined,
        post: undefined,
      },
    };

    const metadata = await readFontMetadataFromFile(
      createMockFile('inter.woff2'),
    );

    expect(metadata).toEqual({
      family: 'Inter',
      variantType: 'variable',
      axes: [
        {
          tag: 'wght',
          name: 'Weight',
          min: 100,
          max: 900,
          default: 400,
        },
        {
          tag: 'wdth',
          name: 'Width',
          min: 75,
          max: 125,
          default: 100,
        },
      ],
      axisSettings: [
        {
          tag: 'wght',
          value: 400,
        },
        {
          tag: 'wdth',
          value: 100,
        },
      ],
      weight: '400',
      style: 'normal',
    });
    expect(parseFontFileMock).toHaveBeenCalledOnce();
  });
});
