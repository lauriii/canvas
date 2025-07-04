import * as React from 'react';

import {
  BoxModelIcon,
  CodeIcon,
  Component1Icon,
  Component2Icon,
  ComponentBooleanIcon,
  CubeIcon,
  DotsHorizontalIcon,
  FileIcon,
  SectionIcon,
} from '@radix-ui/react-icons';
import { DropdownMenu, Flex, Text } from '@radix-ui/themes';
import { clsx } from 'clsx';

import styles from './SidebarNode.module.css';

const VARIANTS = {
  component: { icon: <Component1Icon /> },
  code: { icon: <CodeIcon /> },
  codeComponent: { icon: <Component2Icon /> },
  dynamicComponent: { icon: <ComponentBooleanIcon /> },
  page: { icon: <FileIcon /> },
  region: { icon: <CubeIcon /> },
  pattern: { icon: <SectionIcon /> },
  slot: { icon: <BoxModelIcon /> },
} as const;

export type SideBarNodeVariant = keyof typeof VARIANTS;

const SidebarNode = React.forwardRef<
  HTMLDivElement,
  {
    title: string;
    variant: SideBarNodeVariant;
    leadingContent?: React.ReactNode;
    hovered?: boolean;
    draggable?: boolean;
    selected?: boolean;
    disabled?: boolean;
    dropdownMenuContent?: React.ReactNode;
    open?: boolean;
    className?: string;
    onMenuOpenChange?: (open: boolean) => void;
  } & React.HTMLAttributes<HTMLDivElement>
>(
  (
    {
      title,
      variant = 'component',
      leadingContent,
      hovered = false,
      selected = false,
      draggable = true,
      disabled = false,
      dropdownMenuContent = null,
      open = false,
      className,
      onMenuOpenChange,
      ...props
    },
    ref,
  ) => {
    return (
      <Flex
        ref={ref}
        align="center"
        pr="2"
        maxWidth="100%"
        className={clsx(
          styles[`${variant}Variant`],
          {
            [styles.hovered]: hovered,
            [styles.selected]: selected,
            [styles.disabled]: disabled,
            [styles.draggable]: draggable,
            [styles.open]: open,
          },
          className,
        )}
        {...props}
      >
        <Flex flexGrow="1" align="center" overflow="hidden">
          {leadingContent && (
            <Flex
              mr="-2" // Offset the padding of the element to the right. This will provide a larger area for clicking.
              align="center"
              flexShrink="0"
              flexGrow="0"
              className={styles.leadingContent}
            >
              {leadingContent}
            </Flex>
          )}
          <Flex pl="2" align="center" flexShrink="0" className={styles.icon}>
            {VARIANTS[variant].icon}
          </Flex>
          <Flex px="2" align="center" flexGrow="1" overflow="hidden">
            <Text size="1" className={styles.title}>
              {title}
            </Text>
          </Flex>
        </Flex>
        {dropdownMenuContent && (
          <DropdownMenu.Root onOpenChange={onMenuOpenChange}>
            <DropdownMenu.Trigger>
              <button
                aria-label="Open contextual menu"
                className={styles.contextualTrigger}
              >
                <span className={styles.dots}>
                  <DotsHorizontalIcon />
                </span>
              </button>
            </DropdownMenu.Trigger>
            {dropdownMenuContent}
          </DropdownMenu.Root>
        )}
      </Flex>
    );
  },
);

SidebarNode.displayName = 'SidebarNode';

export default SidebarNode;
