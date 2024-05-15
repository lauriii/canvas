require('dotenv').config();

const { defineConfig } = require("cypress");
const fs = require('fs');

const getCoreDir = () => {
  let count = 0;
  let path = 'core'
  while (!fs.existsSync(path) && count < 15) {
    count +=1
    const stepsup = `../`.repeat(count)
    path = `${stepsup}core`

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
    coreDir: getCoreDir(),
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
