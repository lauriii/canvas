import rootConfig from '../../.prettierrc.json' with { type: 'json' };

export default {
  ...rootConfig,
  importOrderParserPlugins: ['typescript', 'jsx', 'decorators-legacy'],
};
