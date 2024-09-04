import type * as React from 'react';
import { SegmentedControl } from '@radix-ui/themes';
import './Toggle.css';
import inputBehaviors from '@/components/form/inputBehaviors';
import { useRef } from 'react';

const Toggle = (props: React.ComponentProps<any>) => {
  const { attributes = {}, renderChildren = '' } = props;
  // Cast to boolean.
  attributes.value = !!attributes.value;
  const containerRef = useRef<HTMLDivElement>(null);
  return (
    <div className="ToggleContainer">
      {renderChildren}
      <SegmentedControl.Root
        ref={containerRef}
        value={attributes.value ? 'true' : 'false'}
        className="SegmentedControlRoot"
        onValueChange={(newValue) => {
          const booleanValue = newValue === 'true';

          const event = {
            target: {
              checked: booleanValue,
              element: containerRef.current,
              name: attributes.name,
            },
          } as unknown as React.ChangeEvent<HTMLInputElement>;

          if (attributes.onChange) {
            attributes.onChange(event);
          }
        }}
      >
        <SegmentedControl.Item value="true">True</SegmentedControl.Item>
        <SegmentedControl.Item value="false">False</SegmentedControl.Item>
      </SegmentedControl.Root>
    </div>
  );
};

export default inputBehaviors(Toggle);
