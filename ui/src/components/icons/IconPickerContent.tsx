import { useEffect, useMemo, useRef, useState } from 'react';
import clsx from 'clsx';
import { Cross2Icon, MagnifyingGlassIcon } from '@radix-ui/react-icons';
import { Flex, IconButton, Text, TextField } from '@radix-ui/themes';

import IconPreview from '@/components/icons/IconPreview';
import {
  loadRecentIconIds,
  recordRecentIconId,
} from '@/components/icons/iconRecents';
import { filterAndRankPacks } from '@/components/icons/iconSearch';

import type { IconPack, PackIcon } from '@/types/Icons';

import styles from '@/components/icons/IconPickerContent.module.css';

// Must match `grid-template-columns` in IconPickerContent.module.css.
const GRID_COLS = 4;

/**
 * A cell's place in the picker's overall grid geometry.
 *
 * Rows are numbered consecutively across all sections (recently used, then
 * one section per pack), so arrow-key movement flows from one section into
 * the next.
 */
export type GridCellPosition = {
  row: number;
  col: number;
};

/**
 * Computes the cell an arrow or Home/End key moves focus to, or null.
 *
 * Left and right move one cell without wrapping past the ends; up and down
 * move by row, keeping the column (clamped to shorter rows); Home and End
 * jump within the current row.
 */
export function nextCellIndex(
  positions: GridCellPosition[],
  current: number,
  key: string,
): number | null {
  const position = positions[current];
  if (!position) {
    return null;
  }
  if (key === 'ArrowRight') {
    return current + 1 < positions.length ? current + 1 : null;
  }
  if (key === 'ArrowLeft') {
    return current > 0 ? current - 1 : null;
  }
  if (key === 'ArrowDown' || key === 'ArrowUp') {
    const targetRow = key === 'ArrowDown' ? position.row + 1 : position.row - 1;
    const rowIndexes = positions
      .map((candidate, index) => ({ candidate, index }))
      .filter(({ candidate }) => candidate.row === targetRow);
    if (rowIndexes.length === 0) {
      return null;
    }
    const targetCol = Math.min(position.col, rowIndexes.length - 1);
    return rowIndexes[targetCol].index;
  }
  if (key === 'Home' || key === 'End') {
    const rowIndexes = positions
      .map((candidate, index) => ({ candidate, index }))
      .filter(({ candidate }) => candidate.row === position.row);
    return key === 'Home'
      ? rowIndexes[0].index
      : rowIndexes[rowIndexes.length - 1].index;
  }
  return null;
}

const KEYBOARD_KEYS = [
  'ArrowRight',
  'ArrowLeft',
  'ArrowDown',
  'ArrowUp',
  'Home',
  'End',
];

/**
 * The icon picker: a searchable icon grid, per the "Icon Libraries" Figma
 * file (nodes 73-13758 and 73-15448).
 *
 * The header identifies a single library (name and icon count). When more
 * than one pack is shown, the header summarizes all of them, the grid is
 * grouped by pack with one section heading per pack, and a filter row lets
 * the user narrow the grid to one pack. Search always filters across the
 * shown packs at once, ranked by match quality within each pack.
 *
 * The grid is one roving-tabindex composite: a single Tab stop, with arrow
 * keys moving between cells and Enter or Space selecting the focused one.
 */
const IconPickerContent = ({
  packs,
  selectedId,
  onSelect,
  onClose,
  trackRecent = true,
}: {
  packs: IconPack[];
  selectedId?: string | null;
  onSelect: (icon: PackIcon) => void;
  onClose?: () => void;
  /**
   * Whether selections are recorded to (and offered from) recently used.
   * Off for read-only browsing, e.g. the Brand Kit library rows.
   */
  trackRecent?: boolean;
}) => {
  const [searchTerm, setSearchTerm] = useState('');
  const [activePackId, setActivePackId] = useState<string | null>(null);
  const [recentIds] = useState<string[]>(() =>
    trackRecent ? loadRecentIconIds() : [],
  );
  const searchRef = useRef<HTMLInputElement>(null);
  const cellRefs = useRef(new Map<number, HTMLButtonElement>());

  const isSearching = searchTerm.trim() !== '';

  const shownPacks = useMemo(() => {
    const visible = activePackId
      ? packs.filter((pack) => pack.id === activePackId)
      : packs;
    return filterAndRankPacks(visible, searchTerm);
  }, [packs, activePackId, searchTerm]);

  // Recently used icons still present in the shown packs, hidden while
  // searching so results stay unambiguous.
  const recentIcons = useMemo(() => {
    if (!trackRecent || isSearching) {
      return [];
    }
    const iconsById = new Map(
      shownPacks.flatMap((pack) => pack.icons.map((icon) => [icon.id, icon])),
    );
    return recentIds
      .map((id) => iconsById.get(id))
      .filter((icon): icon is PackIcon => icon !== undefined);
  }, [trackRecent, isSearching, shownPacks, recentIds]);

  const totalIcons = packs.reduce((sum, pack) => sum + pack.iconCount, 0);
  const singlePack = packs.length === 1 ? packs[0] : null;
  const headerLabel = singlePack ? singlePack.label : 'Icon libraries';
  const headerCount = singlePack ? singlePack.iconCount : totalIcons;
  const chipIcon = packs[0]?.icons[0];

  // The grid sections in display order, with each cell's flat index and
  // position powering the roving tabindex and arrow-key geometry.
  const sections = useMemo(() => {
    const list: { key: string; heading: string | null; icons: PackIcon[] }[] =
      [];
    if (recentIcons.length > 0) {
      list.push({
        key: '__recents',
        heading: 'Recently used',
        icons: recentIcons,
      });
    }
    for (const pack of shownPacks) {
      list.push({
        key: pack.id,
        heading: singlePack && recentIcons.length === 0 ? null : pack.label,
        icons: pack.icons,
      });
    }
    return list;
  }, [recentIcons, shownPacks, singlePack]);

  const { flatCells, positions, sectionOffsets } = useMemo(() => {
    const cells: PackIcon[] = [];
    const cellPositions: GridCellPosition[] = [];
    const offsets: number[] = [];
    let row = 0;
    for (const section of sections) {
      offsets.push(cells.length);
      section.icons.forEach((icon, index) => {
        cells.push(icon);
        cellPositions.push({
          row: row + Math.floor(index / GRID_COLS),
          col: index % GRID_COLS,
        });
      });
      row += Math.ceil(section.icons.length / GRID_COLS);
    }
    return {
      flatCells: cells,
      positions: cellPositions,
      sectionOffsets: offsets,
    };
  }, [sections]);

  const [focusIndex, setFocusIndex] = useState(() => {
    const selected = flatCells.findIndex((icon) => icon.id === selectedId);
    return selected === -1 ? 0 : selected;
  });

  // Keep the single Tab stop valid as searching or pack filtering changes
  // which cells exist.
  useEffect(() => {
    setFocusIndex((current) => (current < flatCells.length ? current : 0));
  }, [flatCells.length]);

  // Focus starts in the search field. The popover call sites prevent the
  // default Radix auto-focus (which would land on the close button, the first
  // tabbable element) in favor of this.
  useEffect(() => {
    searchRef.current?.focus();
  }, []);

  const handleGridKeyDown = (event: React.KeyboardEvent) => {
    if (!KEYBOARD_KEYS.includes(event.key)) {
      return;
    }
    const cell = (event.target as HTMLElement).closest(
      'button[data-cell-index]',
    );
    if (!(cell instanceof HTMLButtonElement) || !cell.dataset.cellIndex) {
      return;
    }
    const next = nextCellIndex(
      positions,
      Number(cell.dataset.cellIndex),
      event.key,
    );
    if (next !== null) {
      event.preventDefault();
      setFocusIndex(next);
      cellRefs.current.get(next)?.focus();
    }
  };

  const handleSelect = (icon: PackIcon) => {
    if (trackRecent) {
      recordRecentIconId(icon.id);
    }
    onSelect(icon);
  };

  const shownCount = shownPacks.reduce(
    (sum, pack) => sum + pack.icons.length,
    0,
  );
  const activePack = activePackId
    ? packs.find((pack) => pack.id === activePackId)
    : null;
  let statusText: string;
  if (isSearching) {
    statusText =
      shownCount === 0
        ? `No icons match “${searchTerm.trim()}”.`
        : `${shownCount} of ${activePack ? activePack.iconCount : totalIcons} icons match “${searchTerm.trim()}”.`;
  } else if (activePack) {
    statusText = `${activePack.iconCount} ${activePack.iconCount === 1 ? 'icon' : 'icons'} in ${activePack.label}.`;
  } else {
    statusText = `${totalIcons} ${totalIcons === 1 ? 'icon' : 'icons'}.`;
  }

  const renderCell = (icon: PackIcon, cellIndex: number) => (
    <button
      key={icon.id}
      type="button"
      aria-pressed={icon.id === selectedId}
      data-cell-index={cellIndex}
      tabIndex={cellIndex === focusIndex ? 0 : -1}
      ref={(element) => {
        if (element) {
          cellRefs.current.set(cellIndex, element);
        } else {
          cellRefs.current.delete(cellIndex);
        }
      }}
      className={clsx(styles.cell, {
        [styles.cellSelected]: icon.id === selectedId,
      })}
      title={icon.name}
      onFocus={() => setFocusIndex(cellIndex)}
      onClick={() => handleSelect(icon)}
    >
      <IconPreview icon={icon} size={20} />
      <span className={styles.cellLabel}>{icon.name}</span>
    </button>
  );

  return (
    <Flex direction="column" className={styles.picker}>
      <Flex align="center" gap="2" className={styles.header}>
        <span className={styles.libraryChip} aria-hidden="true">
          {chipIcon && <IconPreview icon={chipIcon} size={20} />}
        </span>
        <Flex direction="column" flexGrow="1" minWidth="0">
          <Text
            size="1"
            weight="medium"
            className={styles.libraryName}
            truncate
          >
            {headerLabel}
          </Text>
          <Text size="1" className={styles.libraryCount}>
            {headerCount} {headerCount === 1 ? 'icon' : 'icons'} available
          </Text>
        </Flex>
        {onClose && (
          <IconButton
            aria-label="Close icon picker"
            variant="ghost"
            color="gray"
            size="1"
            onClick={onClose}
          >
            <Cross2Icon width="15" height="15" />
          </IconButton>
        )}
      </Flex>
      <Flex className={styles.searchWrapper}>
        <TextField.Root
          ref={searchRef}
          autoComplete="off"
          placeholder="Search"
          aria-label="Search icons"
          size="2"
          radius="medium"
          value={searchTerm}
          onChange={(e) => setSearchTerm(e.target.value)}
          className={styles.search}
        >
          <TextField.Slot>
            <MagnifyingGlassIcon height="16" width="16" />
          </TextField.Slot>
        </TextField.Root>
      </Flex>
      {packs.length > 1 && (
        <Flex
          wrap="wrap"
          gap="1"
          className={styles.packTabs}
          role="group"
          aria-label="Filter by icon pack"
        >
          <button
            type="button"
            className={styles.packTab}
            aria-pressed={activePackId === null}
            onClick={() => setActivePackId(null)}
          >
            All
          </button>
          {packs.map((pack) => (
            <button
              key={pack.id}
              type="button"
              className={styles.packTab}
              aria-pressed={activePackId === pack.id}
              onClick={() =>
                setActivePackId(activePackId === pack.id ? null : pack.id)
              }
            >
              {pack.label}
            </button>
          ))}
        </Flex>
      )}
      <div className={styles.scrollArea} onKeyDown={handleGridKeyDown}>
        {flatCells.length === 0 && (
          <Text size="1" color="gray" className={styles.noResults}>
            {isSearching ? statusText : 'No icons found.'}
          </Text>
        )}
        {sections.map((section, sectionIndex) => (
          <div key={section.key}>
            {section.heading && (
              <Text size="1" weight="medium" className={styles.packHeading}>
                {section.heading}
              </Text>
            )}
            <div
              className={styles.grid}
              role="group"
              aria-label={section.heading ?? headerLabel}
            >
              {section.icons.map((icon, iconIndex) =>
                renderCell(icon, sectionOffsets[sectionIndex] + iconIndex),
              )}
            </div>
          </div>
        ))}
      </div>
      <Flex align="center" justify="between" gap="2" className={styles.footer}>
        <Text size="1" className={styles.status} role="status">
          {statusText}
        </Text>
        <Text size="1" className={styles.hint} aria-hidden="true">
          ↑↓←→ · Enter
        </Text>
      </Flex>
    </Flex>
  );
};

export default IconPickerContent;
