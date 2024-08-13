import { defineConfig } from 'cypress';
import minimist from 'minimist';
import webpackPreprocessor from '@cypress/webpack-preprocessor';
import { fileURLToPath } from 'url';
import * as fs from 'fs';
import * as path from 'path';
import dotenv from 'dotenv';
import installLogsPrinter from 'cypress-terminal-report/src/installLogsPrinter';
dotenv.config();

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const getCoreDir = () => {
  let count = 0;
  let path = 'core';
  while (!fs.existsSync(path) && count < 15) {
    count += 1;
    const stepsUp = `../`.repeat(count);
    path = `${stepsUp}core`;
  }
  if (fs.existsSync(path)) {
    return path;
  } else {
    throw new Error(`Path not found, stuck at ${path}`);
  }
};

export default defineConfig({
  chromeWebSecurity: false,
  env: {
    baseUrl: process.env.BASE_URL,
    dbUrl: process.env.DB_URL,
    defaultTheme: 'olivero',
    adminTheme: 'claro',
    coreDir: process.env.DRUPAL_ROOT_CORE || getCoreDir(),
    testWebserverUser: process.env.DRUPAL_TEST_WEBSERVER_USER,
    args: minimist(process.argv),
    setupFile: path.resolve('../tests/src/TestSite/XBTestSetup.php'),
  },
  e2e: {
    baseUrl: process.env.BASE_URL,
    setupNodeEvents(on, config) {
      installLogsPrinter(on);
      on('task', {
        log(message) {
          console.log(message);
          return null;
        },
        table(message) {
          console.table(message);

          return null;
        },
      });

      // This makes e2e tests aware of the project's node_modules directory
      // even though those tests are not in a child directory of the path
      // containing it.
      const options = webpackPreprocessor.defaultOptions;
      options.webpackOptions.resolve = {
        modules: [path.resolve(__dirname, 'node_modules'), 'node_modules'],
        extensions: ['.ts', '.js'],
        fullySpecified: false,
        alias: {
          '@': path.resolve(__dirname, 'src/'),
        },
      };
      options.webpackOptions.module.rules.push({
        test: /\.tsx?$/,
        use: 'ts-loader',
        exclude: /node_modules/,
      });

      options.webpackOptions.module.rules.push({
        test: /\.css$/i,
        use: ['style-loader', 'css-loader'],
      });

      on('file:preprocessor', webpackPreprocessor(options));
    },
    specPattern: ['../tests/src/Cypress/cypress/e2e/**/*.cy.{js,ts,jsx,tsx}'],
    supportFile: '../tests/src/Cypress/cypress/support/e2e.js',
    downloadsFolder: '../tests/src/Cypress/cypress/downloads',
    screenshotsFolder: '../tests/src/Cypress/cypress/screenshots',
  },

  component: {
    specPattern: [
      '../tests/src/Cypress/cypress/component/**/*.cy.{js,ts,jsx,tsx}',
      '../tests/src/Cypress/cypress/unit/**/*.cy.{js,ts,jsx,tsx}',
    ],
    devServer: {
      framework: 'react',
      bundler: 'vite',
    },
    indexHtmlFile: '../tests/src/Cypress/cypress/support/component-index.html',
    supportFile: '../tests/src/Cypress/cypress/support/component.js',
    downloadsFolder: '../tests/src/Cypress/cypress/downloads',
    screenshotsFolder: '../tests/src/Cypress/cypress/screenshots',
    fixturesFolder: '../ui/src/mocks/fixtures',
    setupNodeEvents(on, config) {
      on('task', {
        log(message) {
          console.log(message);
          return null;
        },
      });
    },
  },
});
