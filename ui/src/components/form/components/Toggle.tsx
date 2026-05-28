import { useRef } from 'react';
import { Switch } from '@radix-ui/themes';

import { interceptNativeSetter } from './formChangeUtils';

import type { Attributes } from '@/types/DrupalAttribute';

const Toggle = ({
  checked = false,
  onCheckedChange,
  attributes = {},
}: {
  checked?: boolean;
  onCheckedChange?: (checked: boolean) => void;
  attributes?: Attributes;
}) => {
  const checkboxRef = useRef<HTMLInputElement | null>(null);
  return (
    <Switch
      ref={(node) => {
        if (node) {
          checkboxRef.current =
            node?.parentElement &&
            node.parentElement.querySelector('input[type="checkbox"]');
          if (checkboxRef.current && onCheckedChange) {
            interceptNativeSetter(checkboxRef.current, {
              property: 'checked' as keyof HTMLInputElement,
              afterSet: (el: HTMLInputElement, newValue: any) => {
                onCheckedChange(newValue);
              },
            });
          }
        }
      }}
      checked={checked}
      onCheckedChange={onCheckedChange}
      {...attributes}
    />
  );
};

export default Toggle;
