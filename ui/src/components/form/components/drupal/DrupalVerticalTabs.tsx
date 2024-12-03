import { AccordionRoot } from '@/components/form/components/Accordion';
import { a2p } from '@/local_packages/utils.js';

import type { Attributes } from '@/types/DrupalAttribute';

const DrupalVerticalTabs = ({
  attributes = {},
  renderChildren = null,
}: {
  attributes?: Attributes;
  renderChildren?: JSX.Element | null;
}) => {
  return (
    <AccordionRoot
      attributes={a2p(attributes, { 'data-vertical-tabs-panes': true })}
    >
      {renderChildren}
    </AccordionRoot>
  );
};

export default DrupalVerticalTabs;
