import yml from 'eslint-plugin-yml';

export default [
  ...yml.configs['flat/recommended'],
  { ignores: ['.gitlab/**', '.gitlab-ci.yml', '**/node_modules/**'] },
];
