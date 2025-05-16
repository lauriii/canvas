import { Flex, Link, Grid } from '@radix-ui/themes';
import { ExternalLinkIcon } from '@radix-ui/react-icons';
import ExtensionButton from '@/components/extensions/ExtensionButton';
import { handleNonWorkingBtn } from '@/utils/function-utils';
import type React from 'react';
import {
  getBaseUrl,
  getDrupalSettings,
  getXbSettings,
} from '@/utils/drupal-globals';

interface ExtensionsPopoverProps {}

const drupalSettings = getDrupalSettings();
const baseUrl = getBaseUrl();
const xbSettings = getXbSettings();

const ExtensionsList: React.FC<ExtensionsPopoverProps> = () => {
  let extensionsList = [];
  if (drupalSettings && drupalSettings.xbExtension) {
    extensionsList = Object.values(drupalSettings.xbExtension).map((value) => {
      return {
        ...value,
        imgSrc:
          value.imgSrc ||
          `${baseUrl}${xbSettings.xbModulePath}/ui/assets/icons/extension-default-abstract.svg`,
        name: value.name,
        description: value.description,
      };
    });
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
      <Flex justify="end" asChild pb="2">
        <Link
          size="1"
          href=""
          target="_blank"
          onClick={(e: React.MouseEvent<HTMLAnchorElement>) => {
            e.preventDefault();
            handleNonWorkingBtn();
          }}
        >
          Browse extensions&nbsp; <ExternalLinkIcon />
        </Link>
      </Flex>

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
