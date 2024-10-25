import { a2p } from '@/local_packages/utils.js';
import type * as React from 'react';
import inputBehaviors from './inputBehaviors';
import { Checkbox, Radio, TextField } from '@radix-ui/themes';

const Input = (props: React.ComponentProps<any>) => {
  const { attributes = {} } = props;
  switch (attributes?.type) {
    case 'checkbox': {
      // Remove the following attributes from the `attributes` object so they
      // don't get spread onto the `<Checkbox>` component:
      //   * `checked` - Setting the `checked` prop of the `<Checkbox>` component
      //     would result in a controlled checked state, so we use the
      //     `defaultChecked` prop instead. We will need to revisit this once we
      //     hook this element up to the Redux store.
      //   * `type` - The `type` attribute is removed, so it doesn't get added
      //     to the `<button>` element which the `<Checkbox>` component renders.
      //     Anything else than `type="button"` would cause the form to submit on
      //     click.
      const { checked, type, ...remainingAttributes } = attributes;
      return (
        <Checkbox
          {...a2p({
            ...remainingAttributes,
            defaultChecked: attributes.checked === 'checked',
          })}
        />
      );
    }
    case 'radio':
      return <Radio {...a2p(attributes)} value={attributes.value} />;
    case 'hidden':
    case 'submit':
      // The a2p() process converts 'value to 'defaultValue', which is typically
      // what React wants. Explicitly set the value on submit inputs since that
      // is the text it displays.
      return <input {...a2p(attributes)} value={attributes.value || ''} />;
    default:
      return <TextField.Root {...a2p(attributes)} />;
  }
};

export default inputBehaviors(Input);
