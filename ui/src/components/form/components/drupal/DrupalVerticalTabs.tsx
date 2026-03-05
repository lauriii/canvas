import { AccordionRoot } from '@/components/form/components/Accordion';
import { a2p } from '@/local_packages/utils.js';

import type { Attributes } from '@/types/DrupalAttribute';

const DrupalVerticalTabs = ({
  attributes = {},
  children,
}: {
  attributes?: Attributes;
  children?: JSX.Element | null;
}) => {
  return (
    <AccordionRoot
      attributes={a2p(
        attributes,
        {},
        { skipAttributes: ['data-vertical-tabs-panes'] },
      )}
    >
      {children}
    </AccordionRoot>
  );
};

export default DrupalVerticalTabs;
