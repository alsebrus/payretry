const { defineConfig } = require('@playwright/test');
const fs = require('fs');

// NixOS: no bundled Chromium deps; prefer a real Chrome. Override with CHROME_PATH.
const chromeCandidates = [
  process.env.CHROME_PATH,
  '/home/kiber/.nix-profile/bin/google-chrome',
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
];
const executablePath = chromeCandidates.find((p) => p && fs.existsSync(p));

module.exports = defineConfig({
  testDir: __dirname,
  timeout: 120_000,
  retries: 0,
  workers: 1,
  use: {
    baseURL: 'http://localhost:9080',
    launchOptions: { executablePath },
  },
});
