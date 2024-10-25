import { Box, Button, Flex, Popover } from '@radix-ui/themes';
import { Pencil1Icon } from '@radix-ui/react-icons';
import clsx from 'clsx';
import { a2p } from '@/local_packages/utils.js';
import styles from './ContainerTextFormat.module.css';

interface ContainerTextFormatFilterProps {
  attributes: object | null;
  renderChildren?: JSX.Element | null;
  hasParent?: boolean;
}

/**
 * Mapped to `container--text-format-filter-guidelines--xbxb` / `container--text-format-filter-guidelines.html.twig`.
 * @see `ui/src/components/form/twig-to-jsx-component-map.js`
 * @see https://git.drupalcode.org/project/drupal/-/blob/11.x/core/modules/system/templates/container.html.twig
 */
const ContainerTextFormatFilterGuidelines = ({
  attributes = {},
  renderChildren = null,
  hasParent = false,
}: ContainerTextFormatFilterProps) => {
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
 * Mapped to `container--text-format-filter-help--xbxb` / `container--text-format-filter-help.html.twig`.
 * @see `ui/src/components/form/twig-to-jsx-component-map.js`
 * @see https://git.drupalcode.org/project/drupal/-/blob/11.x/core/modules/system/templates/container.html.twig
 */
const ContainerTextFormatFilterHelp = ({
  attributes = {},
  renderChildren = null,
  hasParent = false,
}: ContainerTextFormatFilterProps) => {
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
 * Mapped to `container--text-format-filter-wrapper--xbxb` / `container--text-format-filter-wrapper.html.twig`.
 * @see `ui/src/components/form/twig-to-jsx-component-map.js`
 * @see https://git.drupalcode.org/project/drupal/-/blob/11.x/core/modules/system/templates/container.html.twig
 */
const ContainerTextFormatFilterWrapper = ({
  attributes = {},
  renderChildren = null,
  hasParent = false,
}: ContainerTextFormatFilterProps) => {
  return (
    <Flex
      {...a2p(attributes, {
        class: clsx(hasParent && ['js-form-wrapper', 'form-wrapper']),
      })}
      justify="end"
    >
      {/* @todo Change this to a component that doesn't use a portal and simply hides/shows content. */}
      <Popover.Root>
        <Popover.Trigger>
          <Button variant="soft" size="1">
            <Pencil1Icon width="12" height="12" />
            Text format
          </Button>
        </Popover.Trigger>
        <Popover.Content size="2" width="360px">
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
  ContainerTextFormatFilterGuidelines,
  ContainerTextFormatFilterHelp,
  ContainerTextFormatFilterWrapper,
};
