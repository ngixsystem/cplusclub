import { defineConfig } from '@playwright/test';
export default defineConfig({testDir:'./tests/e2e',workers:1,timeout:90000,use:{baseURL:process.env.BASE_URL||'http://nginx',headless:true,screenshot:'only-on-failure',trace:'retain-on-failure'},reporter:[['list'],['json',{outputFile:'test-results/e2e.json'}]]});
