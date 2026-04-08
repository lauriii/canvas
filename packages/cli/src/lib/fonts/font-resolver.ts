import fs from 'fs/promises';
import path from 'path';
import { createUnifont, providers } from 'unifont';

import type { Unifont } from 'unifont';
import type { FontsConfig } from '../../config.js';

/**
 * Builds a unifont instance with all providers enabled from config.
 * Used to resolve font families to FontFaceData (with URLs) for download and upload.
 */
export async function createFontResolver(
  config: FontsConfig,
  projectRoot: string,
): Promise<Unifont<any>> {
  const list = [
    providers.google(),
    providers.bunny(),
    providers.fontshare(),
    providers.fontsource(),
    providers.npm({
      readFile: (p: string) =>
        fs.readFile(path.resolve(projectRoot, p), 'utf-8').catch(() => null),
      root: projectRoot,
    }),
    ...(config.providers?.adobe?.id?.length
      ? [providers.adobe({ id: config.providers.adobe.id })]
      : []),
  ];
  return createUnifont(list as [(typeof list)[0], ...typeof list]);
}
