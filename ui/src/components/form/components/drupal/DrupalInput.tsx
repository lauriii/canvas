import clsx from 'clsx';

import { a2p } from '@/local_packages/utils.js';

import Checkbox from '@/components/form/components/Checkbox';
import InputBehaviors from '@/components/form/inputBehaviors';
import TextField from '@/components/form/components/TextField';
import { DrupalRadioItem } from '@/components/form/components/drupal/DrupalRadio';

import type { Attributes } from '@/types/DrupalAttribute';
import TextFieldAutocomplete from '@/components/form/components/TextFieldAutocomplete';
import type { MutableRefObject } from 'react';
import { useRef, useEffect } from 'react';

const DrupalInput = ({
  attributes = {},
}: {
  attributes?: Attributes & {
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  };
}) => {
  const inputRef: MutableRefObject<HTMLInputElement | null> =
    useRef<HTMLInputElement | null>(null);
  const className = clsx(attributes.class);

  // The useEffect ensures the ref is now associated with a DOM element so we
  // can ensure that classes meant to appear on the actual input actually appear
  // there, as Radix does not reliably provide the means to do this via props.
  // @see https://github.com/radix-ui/primitives/issues/3240
  useEffect(() => {
    if (
      className &&
      inputRef?.current?.['className'] &&
      className !== inputRef?.current?.['className']
    ) {
      inputRef.current['className'] += ` ${className}`;
    }
    // Ignore because this only needs to be run once to add the initial classes
    // after the ref associated element is rendered.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

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
      if (
        attributes?.class instanceof Array &&
        attributes?.class?.includes('form-autocomplete')
      ) {
        return (
          <TextFieldAutocomplete
            className={clsx(attributes.class)}
            attributes={a2p(attributes, {}, { skipAttributes: ['class'] })}
            ref={inputRef}
          />
        );
      }
      return (
        <TextField
          className={clsx(attributes.class)}
          attributes={a2p(attributes, {}, { skipAttributes: ['class'] })}
          ref={inputRef}
        />
      );
  }
};

export default InputBehaviors(DrupalInput);
