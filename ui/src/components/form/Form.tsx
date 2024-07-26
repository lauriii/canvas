import {a2p} from '@/local_packages/utils.js';
import {createContext, useState} from 'react';
import type * as React from "react";
import styles from './Form.module.css'

interface FormProps {
  attributes: {
    style?: {
      color?: string
      [x: string | number | symbol]: unknown;
    }
    [x: string | number | symbol]: unknown;
  }
  children: string|null
  renderChildren: string|any[]|null
}

type NoopDispatch = () => undefined
export const FormStateContext = createContext<object>({});
export const FormDispatchContext = createContext<React.Dispatch<any>|NoopDispatch>(() => {})

const Form = ({attributes = {}, children = '', renderChildren = ''}: FormProps) => {
  const [formState, setFormState] = useState({formId: attributes['data-drupal-selector'] || ''})
  const existingClass = attributes.class || '';
  attributes.class = `${existingClass} ${styles.componentFieldForm}`;

  return (
    <FormStateContext.Provider value={formState}>
      <FormDispatchContext.Provider value={setFormState}>
        <form {...a2p(attributes)}>
          {renderChildren}
        </form>
      </FormDispatchContext.Provider>
    </FormStateContext.Provider>
  );
}

export default Form;
