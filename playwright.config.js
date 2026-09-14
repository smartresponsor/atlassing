const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/Browser',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  reporter: 'list',
  use: {
    trace: 'retain-on-failure',
  },
});
