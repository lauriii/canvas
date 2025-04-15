import { Box, Button, Flex, Popover } from '@radix-ui/themes';
import { Pencil1Icon } from '@radix-ui/react-icons';
import clsx from 'clsx';
import { a2p } from '@/local_packages/utils.js';
import { useState } from 'react';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from '@/components/form/components/ContainerTextFormat.module.css';

interface DrupalContainerTextFormatFilterProps {
  attributes?: Attributes;
  renderChildren?: JSX.Element | null;
  hasParent?: boolean;
}

/**
 * Mapped to `container--text-format-filter-guidelines.html.twig`.
 * @see https://git.drupalcode.org/project/drupal/-/blob/11.x/core/modules/system/templates/container.html.twig
 */
const DrupalContainerTextFormatFilterGuidelines = ({
  attributes = {},
  renderChildren = null,
  hasParent = false,
}: DrupalContainerTextFormatFilterProps) => {
  return (
    <Box
      {...a2p(attributes, {
        class: clsx(hasParent && ['js-form-wrapper', 'form-wrapper']),
      })}
    >
      {renderChildren}
    </Box>
  );
};

/**
 * Mapped to `container--text-format-filter-help.html.twig`.
 * @see https://git.drupalcode.org/project/drupal/-/blob/11.x/core/modules/system/templates/container.html.twig
 */
const DrupalContainerTextFormatFilterHelp = ({
  attributes = {},
  renderChildren = null,
  hasParent = false,
}: DrupalContainerTextFormatFilterProps) => {
  return (
    <Box
      {...a2p(attributes, {
        class: clsx(hasParent && ['js-form-wrapper', 'form-wrapper']),
      })}
      mb="2"
    >
      {renderChildren}
    </Box>
  );
};

/**
 * Mapped to `container--text-format-filter-wrapper.html.twig`.
 * @see https://git.drupalcode.org/project/drupal/-/blob/11.x/core/modules/system/templates/container.html.twig
 */
const DrupalContainerTextFormatFilterWrapper = ({
  attributes = {},
  renderChildren = null,
  hasParent = false,
}: DrupalContainerTextFormatFilterProps) => {
  const [open, setOpen] = useState(true);
  return (
    <Flex
      {...a2p(attributes, {
        class: clsx(hasParent && ['js-form-wrapper', 'form-wrapper']),
      })}
      justify="end"
    >
      <Popover.Root onOpenChange={(isOpen) => setOpen(isOpen)}>
        <Popover.Trigger>
          <Button variant="soft" size="1">
            <Pencil1Icon width="12" height="12" />
            Text format
          </Button>
        </Popover.Trigger>
        <Popover.Content
          hidden={!open}
          size="2"
          width="360px"
          // The text format select must be in the DOM for editors to work.
          forceMount={true}
          // Change the container because Drupal's editor initialization
          // assumes the format selector is inside the same widget as the
          // textarea using the editor.
          container={document.querySelector(
            `div[data-drupal-selector="${attributes['data-drupal-selector']}"]`,
          )}
          style={{ zIndex: '1' }}
        >
          <div
            className={styles.ContainerTextFormatFilterWrapperPopoverContent}
          >
            {renderChildren}
          </div>
        </Popover.Content>
      </Popover.Root>
    </Flex>
  );
};

export {
  DrupalContainerTextFormatFilterGuidelines,
  DrupalContainerTextFormatFilterHelp,
  DrupalContainerTextFormatFilterWrapper,
};
