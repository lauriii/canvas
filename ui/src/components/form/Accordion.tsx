import * as Accordion from '@radix-ui/react-accordion';
import { Box, Flex, Text } from '@radix-ui/themes';
import { ChevronDownIcon } from '@radix-ui/react-icons';
import clsx from 'clsx';
import { a2p, cleanClass } from '@/local_packages/utils.js';
import styles from './Accordion.module.css';

interface AccordionRootProps {
  attributes: object | null;
  renderChildren?: JSX.Element | null;
}

interface AccordionDetailsProps {
  attributes: object | null;
  errors: JSX.Element | null;
  title: string;
  summaryAttributes: object | null;
  description: JSX.Element | null;
  renderChildren?: JSX.Element | null;
  value: JSX.Element | null;
  required: boolean;
}

/**
 * Mapped to `vertical-tabs.html.twig`/`drupal-vertical-tabs--xbxb`.
 * @see `ui/src/components/form/twig-to-jsx-component-map.js`
 * @see https://git.drupalcode.org/project/drupal/-/blob/11.x/core/modules/system/templates/vertical-tabs.html.twig
 */
const AccordionRoot = ({
  attributes = {},
  renderChildren = null,
}: AccordionRootProps) => {
  return (
    <Accordion.Root
      type="multiple"
      {...a2p(attributes, { 'data-vertical-tabs-panes': true })}
    >
      {renderChildren}
    </Accordion.Root>
  );
};

/**
 * Mapped to `details.html.twig`/`drupal-details--xbxb`.
 * @see `ui/src/components/form/twig-to-jsx-component-map.js`
 * @see https://git.drupalcode.org/project/drupal/-/blob/11.x/core/modules/system/templates/details.html.twig
 */
const AccordionDetails = ({
  attributes = {},
  errors = null,
  title = '',
  summaryAttributes = {},
  description = null,
  renderChildren = null,
  value = null,
  required = false,
}: AccordionDetailsProps) => {
  return (
    <Accordion.Item {...a2p(attributes)} value={cleanClass(title)}>
      <Flex asChild justify="between" align="center" width="100%">
        <Accordion.Trigger className={styles.Trigger}>
          <Text
            size="2"
            weight="medium"
            {...a2p(summaryAttributes, {
              class: clsx(required && ['js-form-required', 'form-required']),
            })}
          >
            {title}
          </Text>
          <ChevronDownIcon className={styles.Chevron} aria-hidden />
        </Accordion.Trigger>
      </Flex>

      <Accordion.Content className={styles.Content}>
        <Box p="1">
          {errors && <Box>{errors}</Box>}
          {description && <Box>{description}</Box>}
          <Box>{renderChildren}</Box>
          {value && <Box>{value}</Box>}
        </Box>
      </Accordion.Content>
    </Accordion.Item>
  );
};

export { AccordionRoot, AccordionDetails };
