import { useMemo, useState } from 'react';
import { ChevronRightIcon, MagnifyingGlassIcon } from '@radix-ui/react-icons';
import {
  Flex,
  Heading,
  Popover,
  Spinner,
  Text,
  TextField,
} from '@radix-ui/themes';

import EmptyStateCallout from '@/components/EmptyStateCallout';
import IconPickerContent from '@/components/icons/IconPickerContent';
import IconPreview from '@/components/icons/IconPreview';
import { useGetIconPacksQuery } from '@/services/icons';

import styles from '@/features/brandKit/BrandKitIconsSection.module.css';

/**
 * Read-only "Icon libraries" section of the Brand Kit panel.
 *
 * Lists every installed icon pack as a row; a row opens the icon browser
 * popover for that library (the same browser the icon prop widget uses). The
 * section-level search bar filters icons across all installed packs at once,
 * showing matches inline. Icon libraries are site-level facts derived from
 * installed packs, so nothing here mutates the BrandKit entity, and no
 * editing, uploading, or deleting is offered.
 */
const BrandKitIconsSection = () => {
  const { data: packs = [], isLoading } = useGetIconPacksQuery();
  const [searchTerm, setSearchTerm] = useState('');
  const [openPackId, setOpenPackId] = useState<string | null>(null);

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

  const isSearching = searchTerm.trim() !== '';

  if (isLoading) {
    return (
      <Flex width="100%" justify="center" py="6">
        <Spinner size="3" loading={true} />
      </Flex>
    );
  }

  return (
    <Flex direction="column" gap="2" data-testid="canvas-brand-kit-icons">
      <Heading as="h5" size="2">
        Icon libraries
      </Heading>

      {packs.length === 0 && (
        <EmptyStateCallout
          my="3"
          title="No icon libraries installed."
          description="Install a module providing an icon pack, or push an icon library with the Canvas CLI, to make icons available to components."
        />
      )}

      {packs.length > 0 && (
        <>
          <TextField.Root
            autoComplete="off"
            placeholder="Search"
            aria-label="Search icons"
            size="2"
            radius="medium"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          >
            <TextField.Slot>
              <MagnifyingGlassIcon height="16" width="16" />
            </TextField.Slot>
          </TextField.Root>

          {isSearching && filteredPacks.length === 0 && (
            <Text size="1" color="gray">
              No icons found.
            </Text>
          )}

          {/* Search results: matching icons across all packs, inline. */}
          {isSearching &&
            filteredPacks.map((pack) => (
              <Flex direction="column" gap="1" key={pack.id}>
                <Flex align="baseline" gap="2">
                  <Text size="1" weight="medium">
                    {pack.label}
                  </Text>
                  <Text size="1" color="gray">
                    {pack.icons.length}{' '}
                    {pack.icons.length === 1 ? 'icon' : 'icons'}
                  </Text>
                </Flex>
                <div className={styles.grid}>
                  {pack.icons.map((icon) => (
                    <div
                      className={styles.cell}
                      key={icon.id}
                      title={icon.name}
                    >
                      <IconPreview icon={icon} size={20} />
                      <span className={styles.cellLabel}>{icon.name}</span>
                    </div>
                  ))}
                </div>
              </Flex>
            ))}

          {/* Default state: one row per library, opening the icon browser. */}
          {!isSearching &&
            packs.map((pack) => (
              <Popover.Root
                key={pack.id}
                modal={false}
                open={openPackId === pack.id}
                onOpenChange={(open) => setOpenPackId(open ? pack.id : null)}
              >
                <Popover.Trigger>
                  <button
                    type="button"
                    className={styles.libraryRow}
                    aria-label={`Browse ${pack.label}`}
                  >
                    <span className={styles.libraryChip} aria-hidden="true">
                      {pack.icons[0] && (
                        <IconPreview icon={pack.icons[0]} size={20} />
                      )}
                    </span>
                    <Flex direction="column" flexGrow="1" minWidth="0">
                      <Text size="1" weight="medium" truncate>
                        {pack.label}
                      </Text>
                      <Text size="1" color="gray">
                        {pack.iconCount}{' '}
                        {pack.iconCount === 1 ? 'icon' : 'icons'}
                      </Text>
                    </Flex>
                    <ChevronRightIcon
                      className={styles.libraryChevron}
                      aria-hidden="true"
                    />
                  </button>
                </Popover.Trigger>
                <Popover.Content
                  side="right"
                  align="start"
                  sideOffset={20}
                  // Inline style so it wins over the Radix theme's default
                  // padding.
                  style={{ padding: 0 }}
                  className={styles.popover}
                  onOpenAutoFocus={(e) => e.preventDefault()}
                >
                  <IconPickerContent
                    packs={[pack]}
                    onSelect={() => {}}
                    onClose={() => setOpenPackId(null)}
                  />
                </Popover.Content>
              </Popover.Root>
            ))}
        </>
      )}
    </Flex>
  );
};

export default BrandKitIconsSection;
