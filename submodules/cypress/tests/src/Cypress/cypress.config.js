const { defineConfig } = require("cypress");

module.exports = defineConfig({
  env: {
    baseUrl: 'http://drupal.test',
    dbUrl: 'sqlite://localhost/sites/default/files/db.sqlite',
    defaultTheme: 'olivero',
    adminTheme: 'claro',
  },
  e2e: {
    setupNodeEvents(on, config) {
      // implement node event listeners here
    },

  },

  component: {
    devServer: {
      framework: 'react',
      bundler: 'webpack',
    },
  },
});
