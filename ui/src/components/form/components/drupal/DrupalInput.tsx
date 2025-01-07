import clsx from 'clsx';

import { a2p } from '@/local_packages/utils.js';

import Checkbox from '@/components/form/components/Checkbox';
import InputBehaviors from '@/components/form/inputBehaviors';
import TextField from '@/components/form/components/TextField';
import { DrupalRadioItem } from '@/components/form/components/drupal/DrupalRadio';

import type { Attributes } from '@/types/DrupalAttribute';

const DrupalInput = ({
  attributes = {},
}: {
  attributes?: Attributes & {
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  };
}) => {
  switch (attributes?.type) {
    case 'checkbox': {
      // Ensure the following attributes don't get passed to the `<Checkbox>`
      // component (which then passes them to the `<Checkbox>` component from
      // Radix Themes):
      //   * `checked` - Setting the `checked` prop of the `<Checkbox>` component
      //     of Radix Themes would result in a controlled checked state, so we use
      //     the `defaultChecked` prop instead. We will need to revisit this once
      //     we hook this element up to the Redux store.
      //   * `type` - The `type` attribute is removed, so it doesn't get added
      //     to the `<button>` element which the `<Checkbox>` component of Radix
      //     Themes renders. Anything other than `type="button"` would cause the
      //     form to submit on click.
      return (
        <Checkbox
          checked={!!attributes.checked}
          onCheckedChange={(value: boolean) => {
            const syntheticEvent = {
              target: {
                checked: value,
                name: attributes.name,
              },
            } as unknown as React.ChangeEvent<HTMLInputElement>;
            attributes?.onChange?.(syntheticEvent);
          }}
          attributes={a2p(
            attributes,
            {},
            { skipAttributes: ['class', 'checked', 'type'] },
          )}
        />
      );
    }
    case 'radio':
      return <DrupalRadioItem attributes={attributes} />;
    case 'hidden':
    case 'submit':
      // The a2p() process converts 'value to 'defaultValue', which is typically
      // what React wants. Explicitly set the value on submit inputs since that
      // is the text it displays.
      return <input {...a2p(attributes)} value={attributes.value || ''} />;
    default:
      return (
        <TextField
          className={clsx(attributes.class)}
          attributes={a2p(attributes, {}, { skipAttributes: ['class'] })}
        />
      );
  }
};

export default InputBehaviors(DrupalInput);
