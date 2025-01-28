import * as React from 'react';

import {
  BoxModelIcon,
  CodeIcon,
  Component1Icon,
  Component2Icon,
  ComponentBooleanIcon,
  CubeIcon,
  DotsHorizontalIcon,
  SectionIcon,
} from '@radix-ui/react-icons';
import { DropdownMenu, Flex, Text } from '@radix-ui/themes';
import { clsx } from 'clsx';

import styles from './SidebarNode.module.css';

const VARIANTS = {
  component: { icon: <Component1Icon /> },
  codeComponent: { icon: <Component2Icon /> },
  blockComponent: { icon: <ComponentBooleanIcon /> },
  section: { icon: <SectionIcon /> },
  slot: { icon: <BoxModelIcon /> },
  code: { icon: <CodeIcon /> },
  region: { icon: <CubeIcon /> },
} as const;

export type SideBarNodeVariant = keyof typeof VARIANTS;

const SidebarNode = React.forwardRef<
  HTMLDivElement,
  {
    title: string;
    variant: SideBarNodeVariant;
    leadingContent?: React.ReactNode;
    hovered?: boolean;
    selected?: boolean;
    dropdownMenuContent?: React.ReactNode;
    open?: boolean;
    className?: string;
  } & React.HTMLAttributes<HTMLDivElement>
>(
  (
    {
      title,
      variant = 'component',
      leadingContent,
      hovered = false,
      selected = false,
      dropdownMenuContent = null,
      open = false,
      className,
      ...props
    },
    ref,
  ) => {
    return (
      <Flex
        ref={ref}
        align="center"
        px="2"
        className={clsx(
          styles[`${variant}Variant`],
          hovered && styles.hovered,
          selected && styles.selected,
          open && styles.open,
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
          <DropdownMenu.Root>
            <DropdownMenu.Trigger>
              <button aria-label="Open contextual menu">
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
