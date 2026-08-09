import { useMemo } from 'react';
import { useParams } from 'react-router';
import { useNavigate } from 'react-router-dom';
import { WidthIcon } from '@radix-ui/react-icons';
import { Button, DropdownMenu } from '@radix-ui/themes';

import BreakpointIcon from '@/components/BreakpointIcon';
import { useTemplateRef } from '@/hooks/useTemplateRef';
import { getViewportSizes } from '@/utils/viewports';

import type React from 'react';
import type { viewportSize } from '@/types/Preview';

interface PreviewWidthSelectorProps {}

const PreviewWidthSelector: React.FC<PreviewWidthSelectorProps> = (props) => {
  const navigate = useNavigate();
  const params = useParams();
  const currentWidth = params.width || 'full';
  // Get viewport sizes (supports theme-level customization).
  const viewportSizes = useMemo(() => getViewportSizes(), []);
  const { isTemplatePreviewRoute } = useTemplateRef();

  const handlePreviewWidthChange = (val: string) => {
    if (isTemplatePreviewRoute) {
      navigate(
        `/preview/template/${params.entityType}/${params.bundle}/${params.entityId}/${params.viewMode}/${val}`,
      );
    } else {
      navigate(`/preview/${params.entityType}/${params.entityId}/${val}`);
    }
  };

  const getCurrentViewport = (): viewportSize | null => {
    if (currentWidth === 'full') {
      return null;
    }
    return viewportSizes.find((vs) => vs.id === currentWidth) || null;
  };

  const currentViewport = getCurrentViewport();
  const displayText = currentViewport
    ? Drupal.t('!name (!width)', {
        '!name': currentViewport.name,
        '!width': `${currentViewport.width}px`,
      })
    : Drupal.t('Full Width');

  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger>
        <Button
          variant="surface"
          color="gray"
          aria-label={Drupal.t('Select preview width')}
        >
          {currentViewport ? (
            <BreakpointIcon width={currentViewport.width} />
          ) : (
            <WidthIcon />
          )}
          {displayText}
          <DropdownMenu.TriggerIcon />
        </Button>
      </DropdownMenu.Trigger>
      <DropdownMenu.Content size="1">
        <DropdownMenu.RadioGroup
          value={currentWidth}
          onValueChange={handlePreviewWidthChange}
        >
          <DropdownMenu.RadioItem
            value="full"
            color={currentWidth === 'full' ? 'blue' : undefined}
          >
            <WidthIcon />
            {Drupal.t('Full Width')}
          </DropdownMenu.RadioItem>
          {viewportSizes.map((vs) => (
            <DropdownMenu.RadioItem
              key={vs.id}
              value={vs.id}
              color={currentWidth === vs.id ? 'blue' : undefined}
            >
              <BreakpointIcon width={vs.width} />
              {Drupal.t('!name (!width)', {
                '!name': vs.name,
                '!width': `${vs.width}px`,
              })}
            </DropdownMenu.RadioItem>
          ))}
        </DropdownMenu.RadioGroup>
      </DropdownMenu.Content>
    </DropdownMenu.Root>
  );
};

export default PreviewWidthSelector;
