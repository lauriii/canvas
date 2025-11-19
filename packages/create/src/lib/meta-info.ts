import packageJson from '../../package.json' with { type: 'json' };

export const getName = (): string => packageJson.name;
export const getDescription = (): string => packageJson.description;
export const getVersion = (): string => packageJson.version;
