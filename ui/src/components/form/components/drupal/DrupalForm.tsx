import { createContext, useState } from 'react';
import clsx from 'clsx';

import { a2p } from '@/local_packages/utils.js';
import Form from '@/components/form/components/Form';

import type { Dispatch, ReactNode } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';

export const FormStateContext = createContext<object>({});
export const FormDispatchContext = createContext<
  Dispatch<any> | (() => undefined)
>(() => {});

const DrupalForm = ({
  attributes = {},
  renderChildren = null,
}: {
  attributes: Attributes;
  renderChildren: ReactNode;
}) => {
  const [formState, setFormState] = useState({
    formId: attributes['data-drupal-selector'] || '',
  });

  return (
    <FormStateContext.Provider value={formState}>
      <FormDispatchContext.Provider value={setFormState}>
        <Form
          attributes={{ ...a2p(attributes, {}, { skipAttributes: ['class'] }) }}
          className={clsx(attributes.class)}
        >
          {renderChildren}
        </Form>
      </FormDispatchContext.Provider>
    </FormStateContext.Provider>
  );
};

export default DrupalForm;
