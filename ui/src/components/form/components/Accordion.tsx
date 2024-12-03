import clsx from 'clsx';
import * as Accordion from '@radix-ui/react-accordion';
import { Box, Flex, Text } from '@radix-ui/themes';
import { ChevronDownIcon } from '@radix-ui/react-icons';

import type { ReactNode } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';

import styles from './AccordionAndDetails.module.css';

const AccordionRoot = ({
  attributes = {},
  children = null,
}: {
  attributes: Attributes;
  children: ReactNode;
}) => (
  <Accordion.Root type="multiple" {...attributes}>
    {children}
  </Accordion.Root>
);

const AccordionDetails = ({
  title = null,
  children = null,
  attributes = {},
  summaryAttributes = {},
}: {
  title: ReactNode;
  children: ReactNode;
  attributes: Attributes;
  summaryAttributes: object;
}) => (
  <Accordion.Item value={attributes.id as string} {...attributes}>
    <Flex asChild justify="between" align="center" width="100%">
      <Accordion.Trigger className={styles.trigger}>
        <Text size="2" weight="medium" {...summaryAttributes}>
          {title}
        </Text>
        <ChevronDownIcon className={styles.chevron} aria-hidden />
      </Accordion.Trigger>
    </Flex>

    <Accordion.Content
      className={clsx(styles.content, styles.accordionContent)}
    >
      <Box p="1">{children}</Box>
    </Accordion.Content>
  </Accordion.Item>
);

export { AccordionRoot, AccordionDetails };
