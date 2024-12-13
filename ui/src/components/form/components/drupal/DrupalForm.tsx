import clsx from 'clsx';

import { a2p } from '@/local_packages/utils.js';
import Form from '@/components/form/components/Form';

import type { ReactNode } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';

const DrupalForm = ({
  attributes = {},
  renderChildren = null,
}: {
  attributes: Attributes;
  renderChildren: ReactNode;
}) => {
  return (
    <Form
      attributes={{ ...a2p(attributes, {}, { skipAttributes: ['class'] }) }}
      className={clsx(attributes.class)}
    >
      {renderChildren}
    </Form>
  );
};

export default DrupalForm;
