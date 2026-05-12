import * as Checkbox from '@radix-ui/react-checkbox';
import * as Switch from '@radix-ui/react-switch';

export default function Example() {
  return (
    <fieldset>
      <Switch.Root aria-label="Enable updates">
        <Switch.Thumb />
      </Switch.Root>
      <Checkbox.Root aria-label="Accept terms">
        <Checkbox.Indicator>✓</Checkbox.Indicator>
      </Checkbox.Root>
    </fieldset>
  );
}
