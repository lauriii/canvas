import { useCallback, useMemo, useState } from 'react';
import * as Collapsible from '@radix-ui/react-collapsible';
import {
  CaretRightIcon,
  Cross2Icon,
  MagnifyingGlassIcon,
} from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';

import { useAppDispatch } from '@/app/hooks';
import ColorPicker from '@/components/ColorPicker';
import { useFieldContext } from '@/components/form/contexts/FieldContext';
import {
  folderfyComponents,
  sortFolderList,
} from '@/components/list/FolderList';
import { useBrandKitColors } from '@/features/brandKit/hooks/useBrandKitColors';
import { setActivePanel } from '@/features/ui/primaryPanelSlice';
import { useGetFoldersQuery } from '@/services/componentAndLayout';
import { ALL_FOLDERS_ID } from '@/utils/colorConstants';
import { getCanvasPermissions } from '@/utils/drupal-globals';

import type { BrandKitColor, BrandKitColorValue } from '@/types/CodeComponent';
import type { FolderInList, FoldersInList } from '@/types/Component';
import type { Attributes } from '@/types/DrupalAttribute';

import styles from './CanvasColorPickerField.module.css';

type ColorMode = 'kit-only' | 'kit-and-free';

interface CanvasColorPickerFieldProps {
  value: string;
  mode: ColorMode;
  onChange?: (value: string) => void;
  allowedFolderIds?: string[];
  attributes?: Attributes;
}

interface ParsedColorValue {
  type: 'kit' | 'hex' | 'hsl' | 'empty';
  kitId?: string;
  hex?: string;
  opacity?: number;
  // HSL fields
  h?: number;
  s?: number;
  l?: number;
}

/**
 * Parse an HSL/HSLA string into components.
 * Matches hsl(h, s%, l%) and hsla(h, s%, l%, a) formats.
 *
 * @param value - The HSL string to parse.
 * @returns Parsed HSL values or null if invalid.
 */
const parseHslString = (
  value: string,
): { h: number; s: number; l: number; alpha: number | null } | null => {
  // Match hsl(h, s%, l%) or hsla(h, s%, l%, a)
  const hslMatch = value.match(
    /^hsl\(\s*(\d+)\s*,\s*(\d+)%\s*,\s*(\d+)%\s*\)$/i,
  );
  const hslaMatch = value.match(
    /^hsla\(\s*(\d+)\s*,\s*(\d+)%\s*,\s*(\d+)%\s*,\s*([\d.]+)\s*\)$/i,
  );

  if (hslaMatch) {
    const h = parseInt(hslaMatch[1], 10);
    const s = parseInt(hslaMatch[2], 10);
    const l = parseInt(hslaMatch[3], 10);
    const alpha = parseFloat(hslaMatch[4]);
    return { h, s, l, alpha };
  }

  if (hslMatch) {
    const h = parseInt(hslMatch[1], 10);
    const s = parseInt(hslMatch[2], 10);
    const l = parseInt(hslMatch[3], 10);
    return { h, s, l, alpha: null };
  }

  return null;
};

// Parse the stored color value
const parseColorValue = (value: string): ParsedColorValue => {
  if (!value) {
    return { type: 'empty' };
  }

  // Brand Kit reference: canvas-color:<uuid>
  if (value.startsWith('canvas-color:')) {
    const parts = value.split(':');
    if (parts.length >= 2) {
      return { type: 'kit', kitId: parts[1] };
    }
    return { type: 'empty' };
  }

  // Hex value: #rrggbb or #rrggbbaa
  if (value.startsWith('#')) {
    const hex = value.slice(0, 7); // #rrggbb
    const alphaHex = value.slice(7, 9); // aa (if present)
    const opacity = alphaHex ? parseInt(alphaHex, 16) / 255 : 1;
    return { type: 'hex', hex, opacity };
  }

  // HSL/HSLA value: hsl(h, s%, l%) or hsla(h, s%, l%, a)
  if (value.startsWith('hsl(') || value.startsWith('hsla(')) {
    const parsed = parseHslString(value);
    if (parsed) {
      return {
        type: 'hsl',
        h: parsed.h,
        s: parsed.s,
        l: parsed.l,
        opacity: parsed.alpha ?? 1,
      };
    }
  }

  return { type: 'empty' };
};

// Convert hex6 + opacity to hex8 (#rrggbbaa)
const toHex8 = (hex6: string, opacity: number): string => {
  const alpha = Math.round(Math.max(0, Math.min(1, opacity)) * 255);
  const alphaHex = alpha.toString(16).padStart(2, '0');
  return hex6 + alphaHex;
};

// Compute CSS color value from BrandKitColorValue
const computeCssColorValue = (value: BrandKitColorValue): string => {
  const { colorSpace, components, alpha } = value;

  switch (colorSpace) {
    case 'srgb': {
      const r = Math.round(components[0] * 255);
      const g = Math.round(components[1] * 255);
      const b = Math.round(components[2] * 255);
      const a = alpha ?? 1;
      return a === 1
        ? `rgb(${r}, ${g}, ${b})`
        : `rgba(${r}, ${g}, ${b}, ${a.toFixed(2)})`;
    }

    case 'hsl': {
      const h = Math.round(components[0]);
      const s = Math.round(components[1]);
      const l = Math.round(components[2]);
      const a = alpha ?? 1;
      return a === 1
        ? `hsl(${h}, ${s}%, ${l}%)`
        : `hsla(${h}, ${s}%, ${l}%, ${a.toFixed(2)})`;
    }

    default:
      return 'rgb(0, 0, 0)';
  }
};

// Parse free-pick hex to BrandKitColorValue
const parseHexToValue = (hex: string, opacity: number): BrandKitColorValue => {
  const clean = hex.replace('#', '');
  const r = parseInt(clean.substring(0, 2), 16);
  const g = parseInt(clean.substring(2, 4), 16);
  const b = parseInt(clean.substring(4, 6), 16);
  const alpha = opacity === 1 ? null : opacity;

  return {
    colorSpace: 'srgb',
    components: [r / 255, g / 255, b / 255],
    alpha,
    hex,
  };
};

// Parse free-pick HSL to BrandKitColorValue
const parseHslToValue = (
  h: number,
  s: number,
  l: number,
  opacity: number,
): BrandKitColorValue => {
  const alpha = opacity === 1 ? null : opacity;

  return {
    colorSpace: 'hsl',
    components: [h, s, l],
    alpha,
    hex: null,
  };
};

const CanvasColorPickerField = ({
  value: initialValue,
  mode,
  onChange,
  allowedFolderIds,
  attributes = {},
}: CanvasColorPickerFieldProps) => {
  const fieldContext = useFieldContext();
  const dispatch = useAppDispatch();
  const [popoverOpen, setPopoverOpen] = useState(false);
  const [value, setValue] = useState(initialValue);
  const [searchTerm, setSearchTerm] = useState('');
  const permissions = getCanvasPermissions();
  // Get Brand Kit data
  const { colors, isLoading: colorsLoading } = useBrandKitColors();
  const { data: foldersData, isLoading: foldersLoading } = useGetFoldersQuery();

  // Build color lookup map for O(1) access
  const colorsById = useMemo(() => {
    if (!colors) return {};
    return Object.fromEntries(colors.map((c) => [c.id, c])) as Record<
      string,
      BrandKitColor
    >;
  }, [colors]);

  // Build folder structure
  const { folderComponents, topLevelComponents } = useMemo(() => {
    return folderfyComponents(
      colorsById,
      foldersData,
      colorsLoading,
      foldersLoading,
      'color',
    );
  }, [colorsById, foldersData, colorsLoading, foldersLoading]);

  const sortedFolders: FoldersInList = useMemo(() => {
    return sortFolderList(folderComponents);
  }, [folderComponents]);

  // Filter folders and top-level colors when allowedFolderIds is specified.
  // ALL_FOLDERS_ID sentinel (or empty/undefined list) means "no filter – show all".
  const hasAllowedFolderFilter =
    allowedFolderIds != null &&
    allowedFolderIds.length > 0 &&
    !allowedFolderIds.includes(ALL_FOLDERS_ID);

  const allowedFilteredFolders: FoldersInList = useMemo(() => {
    if (!hasAllowedFolderFilter) return sortedFolders;
    return sortedFolders.filter((folder) =>
      allowedFolderIds!.includes(folder.id),
    );
  }, [sortedFolders, allowedFolderIds, hasAllowedFolderFilter]);

  const filterBySearch = useCallback(
    (item: BrandKitColor) => {
      if (!searchTerm) return true;
      return item.name?.toLowerCase().includes(searchTerm.toLowerCase());
    },
    [searchTerm],
  );

  // Filter folder contents by search
  const filteredFolders: FoldersInList = useMemo(
    () =>
      allowedFilteredFolders
        .map((folder: FolderInList) => {
          const filteredArray = Object.values(
            folder.items as unknown as Record<string, BrandKitColor>,
          )
            .filter(filterBySearch)
            .sort((a, b) => a.name.localeCompare(b.name));
          return {
            ...folder,
            items: Object.fromEntries(
              filteredArray.map((item) => [item.id, item]),
            ) as unknown as FolderInList['items'],
          };
        })
        .filter((folder: FolderInList) =>
          searchTerm ? Object.keys(folder.items).length > 0 : true,
        ),
    [allowedFilteredFolders, filterBySearch, searchTerm],
  );

  // Total (unfiltered) color counts per folder, for count badges
  const folderTotalCounts = useMemo(
    () =>
      Object.fromEntries(
        allowedFilteredFolders.map((folder) => [
          folder.id,
          Object.values(
            folder.items as unknown as Record<string, BrandKitColor>,
          ).filter(
            (item): item is BrandKitColor =>
              item != null &&
              typeof item === 'object' &&
              'value' in item &&
              item.value != null,
          ).length,
        ]),
      ),
    [allowedFilteredFolders],
  );

  // Filter top-level colors
  const filteredTopLevel = useMemo(() => {
    const topLevelArray = Object.values(
      topLevelComponents || {},
    ) as BrandKitColor[];
    return topLevelArray
      .filter(filterBySearch)
      .sort((a, b) => a.name.localeCompare(b.name));
  }, [topLevelComponents, filterBySearch]);

  // Hide top-level (no folder) colors when a folder filter is active
  const showTopLevel = !hasAllowedFolderFilter;

  // Parse current value
  const parsedValue = useMemo(() => parseColorValue(value), [value]);

  // Get display info for trigger
  const triggerInfo = useMemo(() => {
    if (parsedValue.type === 'kit' && parsedValue.kitId) {
      const color = colorsById[parsedValue.kitId];
      if (color) {
        return {
          label: color.name,
          swatchColor: computeCssColorValue(color.value),
        };
      }
      if (colorsLoading) {
        return { label: '', swatchColor: 'transparent' };
      }
      return {
        label: 'Unknown Brand Color',
        swatchColor: 'transparent',
      };
    }

    if (parsedValue.type === 'hex' && parsedValue.hex) {
      const value = parseHexToValue(parsedValue.hex, parsedValue.opacity ?? 1);
      return {
        label: parsedValue.hex.toUpperCase(),
        swatchColor: computeCssColorValue(value),
      };
    }

    if (
      parsedValue.type === 'hsl' &&
      parsedValue.h != null &&
      parsedValue.s != null &&
      parsedValue.l != null
    ) {
      const value = parseHslToValue(
        parsedValue.h,
        parsedValue.s,
        parsedValue.l,
        parsedValue.opacity ?? 1,
      );
      return {
        label: computeCssColorValue(value),
        swatchColor: computeCssColorValue(value),
      };
    }

    return {
      label: 'Choose color',
      swatchColor: 'transparent',
    };
  }, [parsedValue, colorsById, colorsLoading]);

  // Handle color selection from Brand Kit
  const handleKitColorSelect = (color: BrandKitColor) => {
    const newValue = `canvas-color:${color.id}`;
    setValue(newValue);
    if (fieldContext) {
      fieldContext.triggerChange(newValue);
    }
    if (onChange) {
      onChange(newValue);
    }
    setPopoverOpen(false);
  };

  // Handle color change from free picker
  const handleFreeColorChange = (colorValue: BrandKitColorValue) => {
    let newValue: string;
    const opacity = colorValue.alpha ?? 1;

    if (colorValue.colorSpace === 'hsl') {
      // Store as HSL string: hsl(h, s%, l%) or hsla(h, s%, l%, a)
      const h = Math.round(colorValue.components[0]);
      const s = Math.round(colorValue.components[1]);
      const l = Math.round(colorValue.components[2]);

      if (opacity === 1) {
        newValue = `hsl(${h}, ${s}%, ${l}%)`;
      } else {
        newValue = `hsla(${h}, ${s}%, ${l}%, ${opacity.toFixed(2)})`;
      }
    } else {
      // Store as hex8 for sRGB
      const r = Math.round(colorValue.components[0] * 255);
      const g = Math.round(colorValue.components[1] * 255);
      const b = Math.round(colorValue.components[2] * 255);
      const hex = `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`;
      newValue = toHex8(hex, opacity);
    }

    setValue(newValue);
    if (fieldContext) {
      fieldContext.triggerChange(newValue);
    }
    if (onChange) {
      onChange(newValue);
    }
    // Don't close popover on free color change - let user adjust
  };

  // Handle popover close
  const handleOpenChange = (open: boolean) => {
    setPopoverOpen(open);
  };

  // Render a color row
  const renderColorRow = (color: BrandKitColor) => (
    <button
      key={color.id}
      type="button"
      className={styles.colorRow}
      onClick={() => handleKitColorSelect(color)}
      data-color-row={color.name}
    >
      <div
        className={`${styles.swatch} ${styles.swatchList}`}
        style={{ backgroundColor: computeCssColorValue(color.value) }}
      />
      <span className={styles.colorRowLabel}>{color.name}</span>
    </button>
  );

  // Render folder with its colors
  const renderFolder = (folder: FolderInList) => {
    const folderColors = Object.values(folder.items).filter(
      (item): item is BrandKitColor =>
        item != null &&
        typeof item === 'object' &&
        'value' in item &&
        item.value != null,
    );

    if (folderColors.length === 0) return null;

    const totalCount = folderTotalCounts[folder.id] ?? folderColors.length;

    return (
      <Collapsible.Root key={folder.id} defaultOpen>
        <Collapsible.Trigger asChild>
          <button
            data-color-folder={folder.name}
            type="button"
            className={styles.folderHeader}
          >
            <CaretRightIcon className={styles.folderChevron} />
            <span className={styles.folderName}>{folder.name}</span>
            <span className={styles.countBadge}>{totalCount}</span>
          </button>
        </Collapsible.Trigger>
        <Collapsible.Content className={styles.folderContent}>
          {folderColors.map(renderColorRow)}
        </Collapsible.Content>
      </Collapsible.Root>
    );
  };

  // Render top-level colors (no folder)
  const renderTopLevelColors = () => {
    if (filteredTopLevel.length === 0) return null;
    return filteredTopLevel.map(renderColorRow);
  };

  // Show free color picker?
  const showFreePicker = mode === 'kit-and-free';

  // Show Brand Kit colors?
  const showKitColors = mode === 'kit-only' || mode === 'kit-and-free';

  // Current value for free picker
  const freePickerValue: BrandKitColorValue = useMemo(() => {
    if (parsedValue.type === 'hex' && parsedValue.hex) {
      return parseHexToValue(parsedValue.hex, parsedValue.opacity ?? 1);
    }

    if (
      parsedValue.type === 'hsl' &&
      parsedValue.h != null &&
      parsedValue.s != null &&
      parsedValue.l != null
    ) {
      return parseHslToValue(
        parsedValue.h,
        parsedValue.s,
        parsedValue.l,
        parsedValue.opacity ?? 1,
      );
    }

    // Default red
    return {
      colorSpace: 'srgb',
      components: [1, 0, 0],
      alpha: null,
      hex: '#ff0000',
    };
  }, [parsedValue]);

  return (
    <>
      <Popover.Root open={popoverOpen} onOpenChange={handleOpenChange}>
        <Popover.Trigger asChild>
          <button
            type="button"
            className={`${styles.trigger} ${
              parsedValue.type === 'empty' ? styles.triggerEmpty : ''
            }`}
            aria-label={`${attributes['data-canvas-color-label'] || ''}, currentColor ${triggerInfo.label}`}
          >
            <div
              id={attributes?.id ? `${attributes.id}` : '--'}
              className={`${styles.swatch} ${styles.swatchTrigger}`}
              style={{ backgroundColor: triggerInfo.swatchColor }}
            />
            <span className={styles.triggerLabel}>{triggerInfo.label}</span>
          </button>
        </Popover.Trigger>

        <Popover.Portal
          container={
            document.querySelector<HTMLElement>('.radix-themes') ??
            document.body
          }
        >
          <Popover.Content
            side="left"
            align="start"
            sideOffset={4}
            className={styles.popoverContent}
          >
            {/* Main header */}
            <div className={styles.popoverHeader}>
              <span className={styles.popoverHeaderTitle}>Color picker</span>
              <Popover.Close asChild>
                <button
                  type="button"
                  className={styles.popoverCloseButton}
                  aria-label="Close"
                >
                  <Cross2Icon />
                </button>
              </Popover.Close>
            </div>

            {showFreePicker && (
              <ColorPicker
                value={freePickerValue}
                onChange={handleFreeColorChange}
              />
            )}

            {showFreePicker && showKitColors && (
              <div className={styles.divider} />
            )}

            {showKitColors && (
              <div className={styles.colorList}>
                {/* Brand kit sub-header */}
                <div className={styles.brandKitSubheader}>
                  <span className={styles.brandKitLabel}>Brand kit</span>
                  {permissions?.brandKit && (
                    <button
                      type="button"
                      className={styles.editButton}
                      onClick={() => {
                        dispatch(setActivePanel('brandKit'));
                      }}
                    >
                      Edit
                    </button>
                  )}
                </div>

                {colorsLoading || foldersLoading ? (
                  <div className={styles.emptyState}>Loading...</div>
                ) : colors?.length === 0 ? (
                  <div className={styles.emptyState}>
                    No colors in Brand Kit
                  </div>
                ) : (
                  <>
                    <form
                      className={styles.searchForm}
                      onSubmit={(event) => {
                        event.preventDefault();
                      }}
                    >
                      <input
                        type="text"
                        autoComplete="off"
                        placeholder="Search"
                        aria-label="Search colors"
                        value={searchTerm}
                        onChange={(e) => setSearchTerm(e.target.value)}
                        className={styles.searchInput}
                      />
                      <MagnifyingGlassIcon
                        className={styles.searchIcon}
                        height="16"
                        width="16"
                      />
                    </form>
                    {filteredFolders.map(renderFolder)}
                    {showTopLevel && renderTopLevelColors()}
                  </>
                )}
              </div>
            )}
          </Popover.Content>
        </Popover.Portal>
      </Popover.Root>
    </>
  );
};

export default CanvasColorPickerField;
