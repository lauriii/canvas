import { Flex, SegmentedControl } from '@radix-ui/themes';

import { PACKAGE_MANAGERS } from './types';

import type { PackageManager } from './types';

interface PackageManagerSwitcherProps {
  value: PackageManager;
  onValueChange: (value: PackageManager) => void;
}

const PackageManagerSwitcher = ({
  value,
  onValueChange,
}: PackageManagerSwitcherProps) => (
  // The extra flex container keeps the control at its natural width when it
  // is a direct child of a stretching column layout.
  <Flex>
    <SegmentedControl.Root
      size="1"
      value={value}
      onValueChange={(newValue) => onValueChange(newValue as PackageManager)}
    >
      {PACKAGE_MANAGERS.map((packageManager) => (
        <SegmentedControl.Item key={packageManager} value={packageManager}>
          {packageManager}
        </SegmentedControl.Item>
      ))}
    </SegmentedControl.Root>
  </Flex>
);

export default PackageManagerSwitcher;
