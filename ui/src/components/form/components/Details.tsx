import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { omit } from 'lodash';
import * as Collapsible from '@radix-ui/react-collapsible';
import { ChevronRightIcon } from '@radix-ui/react-icons';
import { Box, Flex, Text } from '@radix-ui/themes';

import type { ReactNode, RefObject } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';

import styles from './AccordionAndDetails.module.css';

/**
 * Whether an element shows, whatever hides it: the `hidden` attribute of a
 * prop whose condition fails, or the CSS of an extension.
 */
const isShown = (element: Element): boolean => {
  const { display } = getComputedStyle(element);
  return display === 'contents'
    ? [...element.children].some(isShown)
    : display !== 'none';
};

/**
 * Follows the props in a section of the component instance form.
 *
 * @returns
 *   `empty`: none of the props shows. `invalid`: a prop has an invalid value.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::preRenderPropSections()
 */
const usePropSection = (
  propsRef: RefObject<HTMLElement | null>,
  isPropSection: boolean,
) => {
  const [state, setState] = useState({ empty: false, invalid: false });
  useLayoutEffect(() => {
    const props = propsRef.current;
    if (!isPropSection || !props) {
      return;
    }
    const update = () => {
      const empty = ![...props.children].some(isShown);
      const invalid =
        props.querySelector('[data-invalid-prop-value="true"]') !== null;
      setState((previous) =>
        previous.empty === empty && previous.invalid === invalid
          ? previous
          : { empty, invalid },
      );
    };
    update();
    // Props are shown, hidden and validated without rendering this section,
    // and an attribute of the form can hide them too.
    const observer = new MutationObserver(update);
    observer.observe(props.closest('form') ?? props, {
      attributes: true,
      childList: true,
      subtree: true,
    });
    return () => observer.disconnect();
  }, [propsRef, isPropSection]);
  return state;
};

/**
 * A collapsible section. Its content stays mounted while it is collapsed, so
 * the form elements in it keep their values and are validated.
 *
 * A section of props of the component instance form (`x-canvas-group`,
 * `x-canvas-advanced`) is hidden while none of its props shows, and is open
 * while one of its props has an invalid value.
 */
const Details = ({
  title = '',
  children = null,
  attributes = {},
  summaryAttributes = {},
}: {
  title: string;
  children: ReactNode;
  attributes: Attributes;
  summaryAttributes: object;
}) => {
  const { open: openAttr, ...restAttributes } = attributes;
  // The trigger is the button and has the expanded state, not the title in it.
  const titleAttributes = omit(summaryAttributes, ['role', 'aria-expanded']);
  const [open, setOpen] = useState(Boolean(openAttr));
  const propsRef = useRef<HTMLDivElement>(null);
  const { empty, invalid } = usePropSection(
    propsRef,
    'data-canvas-prop-section' in attributes,
  );
  // A section with an invalid value opens, and stays open once the value is
  // valid: the user is working in it.
  useEffect(() => {
    if (invalid) {
      setOpen(true);
    }
  }, [invalid]);
  return (
    <Collapsible.Root
      open={open || invalid}
      onOpenChange={(next) => setOpen(next || invalid)}
      hidden={empty}
      {...restAttributes}
    >
      <Flex asChild justify="between" align="center" width="100%">
        <Collapsible.Trigger asChild className={styles.trigger}>
          <button>
            <Text size="2" weight="medium" {...titleAttributes}>
              {title}
            </Text>
            <ChevronRightIcon className={styles.chevron} aria-hidden />
          </button>
        </Collapsible.Trigger>
      </Flex>
      <Collapsible.Content
        forceMount={true}
        className={clsx(styles.content, styles.detailsContent)}
      >
        <Box p="1" ref={propsRef}>
          {children}
        </Box>
      </Collapsible.Content>
    </Collapsible.Root>
  );
};

export default Details;
