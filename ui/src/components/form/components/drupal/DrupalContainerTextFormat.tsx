import clsx from 'clsx';
import { Box } from '@radix-ui/themes';

import { a2p } from '@/local_packages/utils.js';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './DrupalContainerTextFormat.module.css';

interface DrupalContainerTextFormatFilterProps {
  attributes?: Attributes;
  children?: JSX.Element | null;
  hasParent?: boolean;
}

/**
 * Mapped to `container--text-format-filter-guidelines.html.twig`.
 * @see https://git.drupalcode.org/project/drupal/-/blob/11.x/core/modules/system/templates/container.html.twig
 */
const DrupalContainerTextFormatFilterGuidelines = ({
  attributes = {},
  children = null,
  hasParent = false,
}: DrupalContainerTextFormatFilterProps) => {
  return (
    <Box
      {...a2p(attributes, {
        class: clsx(
          styles.help,
          hasParent && ['js-form-wrapper', 'form-wrapper'],
        ),
      })}
    >
      {children}
    </Box>
  );
};

/**
 * Mapped to `container--text-format-filter-help.html.twig`.
 * @see https://git.drupalcode.org/project/drupal/-/blob/11.x/core/modules/system/templates/container.html.twig
 */
const DrupalContainerTextFormatFilterHelp = ({
  attributes = {},
  children = null,
  hasParent = false,
}: DrupalContainerTextFormatFilterProps) => {
  return (
    <Box
      {...a2p(attributes, {
        class: clsx(
          styles.help,
          hasParent && ['js-form-wrapper', 'form-wrapper'],
        ),
      })}
      mb="2"
    >
      {children}
    </Box>
  );
};

export {
  DrupalContainerTextFormatFilterGuidelines,
  DrupalContainerTextFormatFilterHelp,
};
