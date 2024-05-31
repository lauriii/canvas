require('dotenv').config();

const { defineConfig } = require("cypress");
const fs = require('fs');

const getCoreDir = () => {
  let count = 0;
  let path = 'core'
  while (!fs.existsSync(path) && count < 15) {
    count +=1
    const stepsUp = `../`.repeat(count)
    path = `${stepsUp}core`

  }
  if (fs.existsSync(path)) {
    return path;
  }
  else {
    throw new Error(`Path not found, stuck at ${path}`);
  }
}


module.exports = defineConfig({
  env: {
    baseUrl: process.env.BASE_URL,
    dbUrl: process.env.DB_URL,
    defaultTheme: 'olivero',
    adminTheme: 'claro',
    coreDir: process.env.DRUPAL_ROOT_CORE || getCoreDir(),
    testWebserverUser: process.env.DRUPAL_TEST_WEBSERVER_USER,
  },
  e2e: {
    baseUrl: process.env.BASE_URL,
    setupNodeEvents(on, config) {
      on('task', {
        log(message) {
          console.log(message)
          return null
        },
      })
    },
  },

  component: {
    devServer: {
      framework: 'react',
      bundler: 'webpack',
    },
  },
});
