import { DotsHorizontalIcon } from '@radix-ui/react-icons';
import { Box, DropdownMenu, Flex, Popover, Text } from '@radix-ui/themes';

import UnifiedMenu from '@/components/UnifiedMenu';
import FontFamilyFlyout from '@/features/brandKit/components/FontFamilyFlyout';
import {
  buildFontFamilyFormatsLabel,
  buildFontFamilySummary,
  isVariableFontFamily,
} from '@/features/brandKit/fontCss';

import type {
  AssetLibraryFont,
  AssetLibraryFontAxis,
} from '@/types/CodeComponent';

import styles from '../BrandKitPanel.module.css';

// Radix Themes caps popover content at 480px through an inline custom
// property, so the width the two consoles need is passed as a prop.
const FLYOUT_WIDTH = 'min(760px, calc(100vw - 32px))';

type FontGroup = { family: string; fonts: AssetLibraryFont[] };

type FontFamiliesListProps = {
  copiedSnippetId: string | null;
  familyDraft: string;
  groupedFonts: FontGroup[];
  isBusy: boolean;
  onAddVariantClick: (family: string) => void;
  onAxisSettingChange: (
    fontId: string,
    axis: AssetLibraryFontAxis,
    value: string,
  ) => void;
  onAxisSettingCommit: (fontId: string) => void;
  onCopySnippet: (text: string, snippetId: string) => Promise<void>;
  onFamilyCommit: (currentFamily: string) => Promise<void>;
  onOpenFamilyChange: (family: string | null) => void;
  onRemoveFamily: (family: string) => Promise<void>;
  onRemoveFont: (fontId: string) => Promise<void>;
  onSelectFont: (font: AssetLibraryFont) => void;
  onSetFamilyDraft: (value: string) => void;
  onStyleChange: (fontId: string, style: string) => Promise<void>;
  onWeightChange: (fontId: string, value: string) => void;
  onWeightCommit: (fontId: string) => Promise<void>;
  openFamily: string | null;
  selectedFont: AssetLibraryFont | null;
};

const FontFamiliesList = ({
  copiedSnippetId,
  familyDraft,
  groupedFonts,
  isBusy,
  onAddVariantClick,
  onAxisSettingChange,
  onAxisSettingCommit,
  onCopySnippet,
  onFamilyCommit,
  onOpenFamilyChange,
  onRemoveFamily,
  onRemoveFont,
  onSelectFont,
  onSetFamilyDraft,
  onStyleChange,
  onWeightChange,
  onWeightCommit,
  openFamily,
  selectedFont,
}: FontFamiliesListProps) => (
  <Box className={styles.familyList}>
    {groupedFonts.map((fontGroup) => {
      const isOpen = openFamily === fontGroup.family;
      const isVariable = isVariableFontFamily(fontGroup.fonts);

      return (
        <Popover.Root
          key={fontGroup.family}
          modal={false}
          open={isOpen}
          onOpenChange={(nextIsOpen) => {
            onOpenFamilyChange(nextIsOpen ? fontGroup.family : null);
          }}
        >
          <Box
            className={styles.familyRowWrapper}
            data-state={isOpen ? 'active' : 'inactive'}
            data-testid={`canvas-brand-kit-font-family-row-${fontGroup.family}`}
          >
            {/*
              The trigger spans the whole row, badge included: the flyout is
              positioned from the trigger's right edge, and a trigger that
              stopped short of the row would open the flyout over the panel.
              The overflow menu cannot nest inside it, so it sits on top.
            */}
            <Popover.Trigger>
              <button
                type="button"
                className={styles.familyRow}
                data-state={isOpen ? 'active' : 'inactive'}
                aria-expanded={isOpen}
                aria-label={`Open ${fontGroup.family} font details, ${buildFontFamilySummary(
                  fontGroup.fonts,
                )}`}
              >
                <Flex direction="column" className={styles.familyMeta}>
                  <Text size="1" weight="medium" className={styles.familyName}>
                    {fontGroup.family}
                  </Text>
                  <Text size="1" className={styles.familyFormat}>
                    {buildFontFamilyFormatsLabel(fontGroup.fonts)}
                  </Text>
                </Flex>
                <Text size="1" weight="medium" className={styles.familyBadge}>
                  {isVariable ? 'Variable' : 'Static'}
                </Text>
              </button>
            </Popover.Trigger>
            <DropdownMenu.Root>
              <DropdownMenu.Trigger>
                <button
                  type="button"
                  className={styles.familyMenuButton}
                  aria-label={`Open ${fontGroup.family} contextual menu`}
                  data-testid={`canvas-brand-kit-font-family-menu-${fontGroup.family}`}
                >
                  <DotsHorizontalIcon />
                </button>
              </DropdownMenu.Trigger>
              <UnifiedMenu.Content menuType="dropdown">
                {/* Variable families ship every weight in one file, so there is
                    nothing to add a variant to. */}
                {!isVariable && (
                  <UnifiedMenu.Item
                    disabled={isBusy}
                    onClick={() => onAddVariantClick(fontGroup.family)}
                    data-testid="canvas-brand-kit-font-family-add-variant"
                  >
                    Add variant
                  </UnifiedMenu.Item>
                )}
                <UnifiedMenu.Item
                  color="red"
                  disabled={isBusy}
                  onClick={() => void onRemoveFamily(fontGroup.family)}
                  data-testid="canvas-brand-kit-font-family-delete"
                >
                  Delete font
                </UnifiedMenu.Item>
              </UnifiedMenu.Content>
            </DropdownMenu.Root>
          </Box>
          <Popover.Content
            side="right"
            sideOffset={32}
            align="start"
            className={styles.flyoutContent}
            width={FLYOUT_WIDTH}
            maxWidth={FLYOUT_WIDTH}
            onOpenAutoFocus={(event) => event.preventDefault()}
          >
            <FontFamilyFlyout
              copiedSnippetId={copiedSnippetId}
              familyDraft={familyDraft}
              fontGroup={fontGroup}
              isBusy={isBusy}
              onAddVariantClick={onAddVariantClick}
              onAxisSettingChange={onAxisSettingChange}
              onAxisSettingCommit={onAxisSettingCommit}
              onCopySnippet={onCopySnippet}
              onFamilyCommit={onFamilyCommit}
              onRemoveFont={onRemoveFont}
              onSelectFont={onSelectFont}
              onSetFamilyDraft={onSetFamilyDraft}
              onStyleChange={onStyleChange}
              onWeightChange={onWeightChange}
              onWeightCommit={onWeightCommit}
              selectedFont={isOpen ? selectedFont : null}
            />
          </Popover.Content>
        </Popover.Root>
      );
    })}
  </Box>
);

export default FontFamiliesList;
