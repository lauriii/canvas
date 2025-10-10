import { Flex, Grid } from '@radix-ui/themes';

import ExtensionButton from '@/components/extensions/ExtensionButton';
import { useGetExtensionsQuery } from '@/services/extensions';
import {
  getBaseUrl,
  getCanvasSettings,
  getDrupalSettings,
} from '@/utils/drupal-globals';

import type React from 'react';
import type { Extension, LegacyExtension } from '@/types/Extensions';

interface ExtensionsPopoverProps {}

const drupalSettings = getDrupalSettings();
const baseUrl = getBaseUrl();
const canvasSettings = getCanvasSettings();

const ExtensionsList: React.FC<ExtensionsPopoverProps> = () => {
  const { data: extensionsData, isLoading, error } = useGetExtensionsQuery();

  // Get legacy extensions from drupalSettings.
  // @todo #3551404: Delete when removing legacy Extensions API
  const legacyExtensions: Extension[] = [];
  if (drupalSettings && drupalSettings.canvasExtension) {
    legacyExtensions.push(
      ...Object.values(
        drupalSettings.canvasExtension as Record<string, LegacyExtension>,
      ).map((legacyExtensionDefinition) => {
        const { id, name, description, imgSrc } = legacyExtensionDefinition;
        // Convert metadata: LegacyExtension → Extension.
        const extension: Extension = {
          id,
          name,
          description,
          icon:
            imgSrc ||
            `${baseUrl}${canvasSettings.canvasModulePath}/ui/assets/icons/extension-default.svg`,
          url: '',
          api_version: '1.0',
        };
        return extension;
      }),
    );
  }

  // Get extensions.
  const extensions = extensionsData
    ? extensionsData.map((extension: Extension) => {
        const { icon } = extension;
        return {
          ...extension,
          icon:
            icon ||
            `${baseUrl}${canvasSettings.canvasModulePath}/ui/assets/icons/extension-default.svg`,
        };
      })
    : [];

  // Merge both sources and sort alphabetically by name.
  // @todo #3551404: Delete when removing legacy Extensions API
  const extensionsList = [...legacyExtensions, ...extensions].sort((a, b) =>
    a.name.localeCompare(b.name),
  );

  // @todo Create nice loading state with skeleton UI.
  if (isLoading) {
    return (
      <Flex justify="center">
        <p>Loading extensions...</p>
      </Flex>
    );
  }

  // @todo Move error handling to a useEffect, use error boundary.
  if (error) {
    // If API fails, still show extensions from drupalSettings if available.
    if (legacyExtensions.length > 0) {
      const sortedSettings = legacyExtensions.sort((a, b) =>
        a.name.localeCompare(b.name),
      );
      return <ExtensionsListDisplay extensions={sortedSettings} />;
    }

    return (
      <Flex justify="center">
        <p>Error loading extensions</p>
      </Flex>
    );
  }

  return <ExtensionsListDisplay extensions={extensionsList} />;
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
