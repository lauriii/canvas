import { useMemo, useState } from 'react';
import clsx from 'clsx';
import { Cross2Icon, MagnifyingGlassIcon } from '@radix-ui/react-icons';
import { Flex, IconButton, Text, TextField } from '@radix-ui/themes';

import IconPreview from '@/components/icons/IconPreview';

import type { IconPack, PackIcon } from '@/types/Icons';

import styles from '@/components/icons/IconPickerContent.module.css';

/**
 * The icon picker: a searchable icon grid, per the "Icon Libraries" Figma
 * file (nodes 73-13758 and 73-15448).
 *
 * The header identifies a single library (name and icon count). When more
 * than one pack is browsable, the header summarizes all of them, and the grid
 * is grouped by pack with one section heading per pack. Search always filters
 * across all browsable packs at once.
 */
const IconPickerContent = ({
  packs,
  selectedId,
  onSelect,
  onClose,
}: {
  packs: IconPack[];
  selectedId?: string | null;
  onSelect: (icon: PackIcon) => void;
  onClose?: () => void;
}) => {
  const [searchTerm, setSearchTerm] = useState('');

  const filteredPacks = useMemo(() => {
    const term = searchTerm.trim().toLowerCase();
    if (!term) {
      return packs;
    }
    return packs
      .map((pack) => ({
        ...pack,
        icons: pack.icons.filter(
          (icon) =>
            icon.name.toLowerCase().includes(term) ||
            icon.label.toLowerCase().includes(term),
        ),
      }))
      .filter((pack) => pack.icons.length > 0);
  }, [packs, searchTerm]);

  const totalIcons = packs.reduce((sum, pack) => sum + pack.iconCount, 0);
  const singlePack = packs.length === 1 ? packs[0] : null;
  const headerLabel = singlePack ? singlePack.label : 'Icon libraries';
  const headerCount = singlePack ? singlePack.iconCount : totalIcons;
  const chipIcon = packs[0]?.icons[0];

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
      <div className={styles.scrollArea}>
        {filteredPacks.length === 0 && (
          <Text size="1" color="gray" className={styles.noResults}>
            No icons found.
          </Text>
        )}
        {filteredPacks.map((pack) => (
          <div key={pack.id}>
            {!singlePack && (
              <Text size="1" weight="medium" className={styles.packHeading}>
                {pack.label}
              </Text>
            )}
            <div className={styles.grid} role="listbox" aria-label={pack.label}>
              {pack.icons.map((icon) => (
                <button
                  key={icon.id}
                  type="button"
                  role="option"
                  aria-selected={icon.id === selectedId}
                  className={clsx(styles.cell, {
                    [styles.cellSelected]: icon.id === selectedId,
                  })}
                  title={icon.name}
                  onClick={() => onSelect(icon)}
                >
                  <IconPreview icon={icon} size={20} />
                  <span className={styles.cellLabel}>{icon.name}</span>
                </button>
              ))}
            </div>
          </div>
        ))}
      </div>
    </Flex>
  );
};

export default IconPickerContent;
