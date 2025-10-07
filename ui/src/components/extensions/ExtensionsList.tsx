import { Flex, Grid } from '@radix-ui/themes';

import ExtensionButton from '@/components/extensions/ExtensionButton';
import {
  getBaseUrl,
  getCanvasSettings,
  getDrupalSettings,
} from '@/utils/drupal-globals';

import type React from 'react';

interface ExtensionsPopoverProps {}

const drupalSettings = getDrupalSettings();
const baseUrl = getBaseUrl();
const canvasSettings = getCanvasSettings();

const ExtensionsList: React.FC<ExtensionsPopoverProps> = () => {
  let extensionsList = [];
  if (drupalSettings && drupalSettings.canvasExtension) {
    extensionsList = Object.values(drupalSettings.canvasExtension).map(
      (value) => {
        return {
          ...value,
          imgSrc:
            value.imgSrc ||
            `${baseUrl}${canvasSettings.canvasModulePath}/ui/assets/icons/extension-default-abstract.svg`,
          name: value.name,
          description: value.description,
        };
      },
    );
  }

  return <ExtensionsListDisplay extensions={extensionsList || []} />;
};

interface ExtensionsListDisplayProps {
  extensions: Array<any>;
}

const ExtensionsListDisplay: React.FC<ExtensionsListDisplayProps> = ({
  extensions,
}) => {
  return (
    <>
      {extensions.length > 0 && (
        <Grid columns="2" gap="3">
          {extensions.map((extension) => (
            <ExtensionButton extension={extension} key={extension.id} />
          ))}
        </Grid>
      )}
      {extensions?.length === 0 && (
        <Flex justify="center">
          <p>No extensions found</p>
        </Flex>
      )}
    </>
  );
};

export { ExtensionsListDisplay };

export default ExtensionsList;
