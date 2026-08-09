import { Cross2Icon } from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import { Box, Button, Flex, IconButton, Text } from '@radix-ui/themes';

import type { Measurable } from '@radix-ui/rect';
import type { BrandKitColor } from '@/types/CodeComponent';

import styles from './FindColorInstancesPopover.module.css';

interface FindColorInstancesPopoverProps {
  color: BrandKitColor;
  anchorRef: React.RefObject<Measurable>;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

const FindColorInstancesPopover = ({
  color,
  anchorRef,
  open,
  onOpenChange,
}: FindColorInstancesPopoverProps) => {
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
          data-testid="canvas-find-color-instances-popover"
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
            <Text
              size="2"
              weight="bold"
              data-testid="find-color-instances-title"
            >
              {Drupal.t('Find instances')}
            </Text>
            <Popover.Close asChild>
              <IconButton
                variant="ghost"
                size="1"
                aria-label={Drupal.t('Close')}
                data-testid="find-color-instances-close-button"
              >
                <Cross2Icon />
              </IconButton>
            </Popover.Close>
          </Flex>

          <Box px="3" py="4">
            <Text
              size="2"
              color="gray"
              data-testid="find-color-instances-description"
            >
              {Drupal.t('Color usage listing for !name will go here.', {
                '!name': color.name,
              })}
            </Text>
          </Box>

          {/* Footer with close button */}
          <Flex
            gap="2"
            justify="end"
            px="3"
            pb="3"
            pt="2"
            className={styles.footer}
          >
            <Popover.Close asChild>
              <Button
                variant="outline"
                size="1"
                data-testid="find-color-instances-footer-close-button"
              >
                {Drupal.t('Close')}
              </Button>
            </Popover.Close>
          </Flex>
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
};

export default FindColorInstancesPopover;
