import type React from 'react';
import { SegmentedControl } from '@radix-ui/themes';
import { useNavigate } from 'react-router-dom';
import { viewportSizes } from '@/types/Preview';

interface PreviewWidthSelectorProps {}

const PreviewWidthSelector: React.FC<PreviewWidthSelectorProps> = (props) => {
  const navigate = useNavigate();
  function handlePreviewWidthChange(val: 'full' | 'desktop' | 'mobile') {
    navigate(`/preview/${val}`);
  }
  const viewPorts = viewportSizes.filter((vs) => {
    return vs.width < window.innerWidth;
  });

  return (
    <SegmentedControl.Root
      defaultValue="full"
      onValueChange={handlePreviewWidthChange}
    >
      <SegmentedControl.Item value="full">Full</SegmentedControl.Item>
      {viewPorts.map((vs) => (
        <SegmentedControl.Item key={vs.id} value={vs.id}>
          {vs.name}
        </SegmentedControl.Item>
      ))}
    </SegmentedControl.Root>
  );
};

export default PreviewWidthSelector;
