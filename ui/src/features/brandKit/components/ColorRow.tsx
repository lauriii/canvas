import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { useDraggable } from '@dnd-kit/core';
import { DotsHorizontalIcon } from '@radix-ui/react-icons';
import {
  ContextMenu,
  DropdownMenu,
  Flex,
  Text,
  TextField,
} from '@radix-ui/themes';

import UnifiedMenu from '@/components/UnifiedMenu';
import ColorFormPopover from '@/features/brandKit/components/ColorFormPopover';
import DeleteColorPopover from '@/features/brandKit/components/DeleteColorPopover';
import FindColorInstancesPopover from '@/features/brandKit/components/FindColorInstancesPopover';
import { useUpdateColorMutation } from '@/services/brandKit';
import { getColorHex, getCssColorValue } from '@/utils/brandKitColor';

import type { Measurable } from '@radix-ui/rect';
import type { BrandKitColor } from '@/types/CodeComponent';

import styles from './ColorRow.module.css';

interface ColorRowProps {
  color: BrandKitColor;
}

const ColorRow = ({ color }: ColorRowProps) => {
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
    id: color.id,
    data: {
      origin: 'library',
      type: 'color',
      item: color,
      name: color.name,
    },
  });

  // RTK mutation
  const [
    updateColor,
    {
      isLoading: isRenamingLoading,
      isError: isRenameError,
      error: renameError,
      reset: resetRename,
    },
  ] = useUpdateColorMutation();

  // Rename state
  const [isRenaming, setIsRenaming] = useState(false);
  const [colorName, setColorName] = useState(color.name);
  const [renameErrorMessage, setRenameErrorMessage] = useState('');
  const inputRef = useRef<HTMLInputElement>(null);
  const isSubmittingRef = useRef(false);

  // Popover state
  const [isEditPopoverOpen, setIsEditPopoverOpen] = useState(false);
  const [isDeletePopoverOpen, setIsDeletePopoverOpen] = useState(false);
  const [isFindInstancesPopoverOpen, setIsFindInstancesPopoverOpen] =
    useState(false);
  const [, setIsMenuOpen] = useState(false);

  // Tracks whether a popover open is pending so the DropdownMenu's
  // onCloseAutoFocus can suppress focus-return (which would immediately
  // close the popover via an interact-outside event).
  const openingPopoverRef = useRef(false);

  // Anchor ref — all three popovers anchor to the dots button
  const dotsButtonRef = useRef<Measurable>(null);

  // Focus and select when entering rename mode
  useEffect(() => {
    if (isRenaming && inputRef.current) {
      inputRef.current.focus();
      inputRef.current.select();
    }
  }, [isRenaming]);

  // Sync colorName when color prop changes (e.g., after successful rename)
  useEffect(() => {
    if (!isRenaming) {
      setColorName(color.name);
    }
  }, [color.name, isRenaming]);

  // Handle rename success/error
  useEffect(() => {
    if (!isRenaming) {
      return;
    }

    if (!isRenamingLoading && !isRenameError && isSubmittingRef.current) {
      setIsRenaming(false);
      isSubmittingRef.current = false;
      setRenameErrorMessage('');
    }

    if (isRenameError && renameError) {
      setRenameErrorMessage(String(renameError));
      isSubmittingRef.current = false;
      if (inputRef.current) {
        inputRef.current.focus();
      }
    }
  }, [isRenaming, isRenamingLoading, isRenameError, renameError]);

  const handleRenameSubmit = async () => {
    if (isSubmittingRef.current) {
      return;
    }

    const trimmedName = colorName.trim();

    if (!trimmedName || trimmedName === color.name) {
      setIsRenaming(false);
      setColorName(color.name);
      return;
    }

    isSubmittingRef.current = true;
    setRenameErrorMessage('');

    try {
      await updateColor({
        id: color.id,
        changes: { name: trimmedName },
      }).unwrap();
    } catch (err) {
      console.error('Failed to rename color:', err);
    }
  };

  const handleRenameCancel = () => {
    setIsRenaming(false);
    setColorName(color.name);
    isSubmittingRef.current = false;
    setRenameErrorMessage('');
    resetRename();
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      handleRenameSubmit();
    } else if (e.key === 'Escape') {
      handleRenameCancel();
    }
  };

  const handleBlur = () => {
    if (isSubmittingRef.current) {
      return;
    }
    handleRenameCancel();
  };

  const menuItems = (
    <>
      <UnifiedMenu.Item
        onClick={() => {
          openingPopoverRef.current = true;
          setIsEditPopoverOpen(true);
        }}
        data-testid="canvas-color-row-edit"
      >
        Edit color
      </UnifiedMenu.Item>
      <UnifiedMenu.Item
        onClick={() => setIsRenaming(true)}
        data-testid="canvas-color-row-rename"
      >
        Rename
      </UnifiedMenu.Item>
      <UnifiedMenu.Item
        onClick={() => {
          openingPopoverRef.current = true;
          setIsFindInstancesPopoverOpen(true);
        }}
        data-testid="canvas-color-row-find-instances"
      >
        Find instances
      </UnifiedMenu.Item>
      <UnifiedMenu.Separator />
      <UnifiedMenu.Item
        onClick={() => {
          openingPopoverRef.current = true;
          setIsDeletePopoverOpen(true);
        }}
        color="red"
        data-testid="canvas-color-row-delete"
      >
        Delete
      </UnifiedMenu.Item>
    </>
  );

  return (
    <>
      <div ref={setNodeRef} {...attributes} {...listeners}>
        <ContextMenu.Root onOpenChange={setIsMenuOpen}>
          <ContextMenu.Trigger>
            <Flex
              align="center"
              className={clsx(styles.colorRow, {
                [styles.isDragging]: isDragging,
              })}
              data-popover-open={
                isEditPopoverOpen ||
                isDeletePopoverOpen ||
                isFindInstancesPopoverOpen
                  ? true
                  : undefined
              }
              data-testid={`canvas-color-row-${color.name}`}
            >
              {/* Color swatch */}
              <div
                style={{
                  width: '16px',
                  height: '16px',
                  borderRadius: 'var(--radius-1)',
                  backgroundColor: getCssColorValue(color.value),
                  border: '1px solid var(--gray-6)',
                  flexShrink: 0,
                }}
              />

              {/* Name or rename input */}
              <Flex flexGrow="1" px="2" overflow="hidden" direction="column">
                {isRenaming ? (
                  <>
                    <TextField.Root
                      ref={inputRef}
                      value={colorName}
                      onChange={(e) => setColorName(e.target.value)}
                      onBlur={handleBlur}
                      onKeyDown={handleKeyDown}
                      size="1"
                      style={{ width: '100%' }}
                      data-testid="canvas-color-row-rename-input"
                    />
                    {renameErrorMessage && (
                      <Text size="1" color="red" style={{ marginTop: '2px' }}>
                        {renameErrorMessage}
                      </Text>
                    )}
                  </>
                ) : (
                  <Text
                    size="1"
                    truncate
                    style={{ flex: 1 }}
                    onDoubleClick={() => setIsRenaming(true)}
                  >
                    {colorName}
                  </Text>
                )}
              </Flex>

              {!isRenaming && (
                <Text
                  size="1"
                  className={styles.hexCode}
                  style={{ color: '#646464' }}
                >
                  {(() => {
                    const format =
                      color.displayFormat ??
                      (color.value.colorSpace === 'hsl' ? 'hsl' : 'hex');

                    switch (format) {
                      case 'hsl':
                        return `hsl(${Math.round(color.value.components[0])}, ${Math.round(color.value.components[1])}%, ${Math.round(color.value.components[2])}%)`;

                      case 'rgb': {
                        const r = Math.round(color.value.components[0] * 255);
                        const g = Math.round(color.value.components[1] * 255);
                        const b = Math.round(color.value.components[2] * 255);
                        return `rgb(${r}, ${g}, ${b})`;
                      }

                      case 'hex':
                      default:
                        return (
                          color.value.hex ?? getColorHex(color.value)
                        ).toUpperCase();
                    }
                  })()}
                </Text>
              )}

              <DropdownMenu.Root onOpenChange={setIsMenuOpen}>
                <DropdownMenu.Trigger>
                  <button
                    ref={dotsButtonRef as React.RefObject<HTMLButtonElement>}
                    aria-label="Open contextual menu"
                    className={styles.colorRowDots}
                  >
                    <DotsHorizontalIcon />
                  </button>
                </DropdownMenu.Trigger>
                <UnifiedMenu.Content
                  menuType="dropdown"
                  onCloseAutoFocus={(e: Event) => {
                    if (openingPopoverRef.current) {
                      e.preventDefault();
                      openingPopoverRef.current = false;
                    }
                  }}
                >
                  {menuItems}
                </UnifiedMenu.Content>
              </DropdownMenu.Root>
            </Flex>
          </ContextMenu.Trigger>
          <UnifiedMenu.Content menuType="context" align="start" side="right">
            {menuItems}
          </UnifiedMenu.Content>
        </ContextMenu.Root>
      </div>

      {/* Popovers — each owns its Popover.Root; all anchor to the dots button */}
      <ColorFormPopover
        operation="edit"
        color={color}
        anchorRef={dotsButtonRef}
        open={isEditPopoverOpen}
        onOpenChange={setIsEditPopoverOpen}
      />
      <DeleteColorPopover
        color={color}
        anchorRef={dotsButtonRef}
        open={isDeletePopoverOpen}
        onOpenChange={setIsDeletePopoverOpen}
      />
      <FindColorInstancesPopover
        color={color}
        anchorRef={dotsButtonRef}
        open={isFindInstancesPopoverOpen}
        onOpenChange={setIsFindInstancesPopoverOpen}
      />
    </>
  );
};

export default ColorRow;
