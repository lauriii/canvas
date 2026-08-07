import { Cross2Icon, TrashIcon } from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import { Box, Button, Flex, IconButton, Text } from '@radix-ui/themes';

import ErrorCard from '@/components/error/ErrorCard';
import { useDeleteColorMutation } from '@/services/brandKit';

import type { Measurable } from '@radix-ui/rect';
import type { BrandKitColor } from '@/types/CodeComponent';

import styles from './DeleteColorPopover.module.css';

interface DeleteColorPopoverProps {
  color: BrandKitColor;
  anchorRef: React.RefObject<Measurable>;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

const DeleteColorPopover = ({
  color,
  anchorRef,
  open,
  onOpenChange,
}: DeleteColorPopoverProps) => {
  const [deleteColor, { isLoading: isDeleting, isError, error, reset }] =
    useDeleteColorMutation();

  const handleDelete = async () => {
    try {
      await deleteColor(color.id).unwrap();
      onOpenChange(false);
      reset();
    } catch (err) {
      console.error('Failed to delete color:', err);
    }
  };

  const errorMessage =
    isError && error
      ? error && 'data' in error
        ? (error.data as { message?: string })?.message
        : String(error)
      : null;

  return (
    <Popover.Root open={open} onOpenChange={onOpenChange}>
      <Popover.Anchor virtualRef={anchorRef} />
      <Popover.Portal
        container={
          document.querySelector<HTMLElement>('.radix-themes') ?? document.body
        }
      >
        <Popover.Content
          side="bottom"
          align="start"
          sideOffset={4}
          className={styles.popoverContent}
          onOpenAutoFocus={(e) => e.preventDefault()}
          onInteractOutside={(e) => {
            const target = e.target as Element | null;
            if (target?.hasAttribute('data-radix-menu-content')) {
              e.preventDefault();
            }
          }}
        >
          {/* Header with title and close button */}
          <Flex
            justify="between"
            align="center"
            className={styles.header}
            px="3"
            py="3"
          >
            <Flex gap="2" align="center">
              <TrashIcon className={styles.titleIcon} />
              <Text size="2" weight="bold">
                Delete color
              </Text>
            </Flex>
            <Popover.Close asChild>
              <IconButton variant="ghost" size="1" aria-label="Close">
                <Cross2Icon />
              </IconButton>
            </Popover.Close>
          </Flex>

          <Box px="3" py="4">
            <Text size="2">
              You are about to permanently delete the <b>{color.name}</b> color.
            </Text>
          </Box>

          {errorMessage && (
            <Box px="3" pb="3">
              <ErrorCard title="Failed to delete color" error={errorMessage} />
            </Box>
          )}

          {/* Footer with action buttons */}
          <Flex
            gap="2"
            justify="end"
            px="3"
            pb="3"
            pt="2"
            className={styles.footer}
          >
            <Popover.Close asChild>
              <Button variant="outline" size="1">
                Cancel
              </Button>
            </Popover.Close>
            <Button
              onClick={handleDelete}
              loading={isDeleting}
              size="1"
              color="red"
            >
              Delete
            </Button>
          </Flex>
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
};

export default DeleteColorPopover;
