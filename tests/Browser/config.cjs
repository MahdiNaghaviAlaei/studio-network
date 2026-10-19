const path = require('node:path');

const baseUrl = (process.env.BROWSER_BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const email = process.env.BROWSER_EMAIL;
const password = process.env.BROWSER_PASSWORD;
if (!email || !password) throw new Error('Set BROWSER_EMAIL and BROWSER_PASSWORD to an existing local admin account.');

module.exports = {
    baseUrl, email, password,
    chromePath: process.env.CHROME_PATH || (process.platform === 'win32'
        ? path.join(process.env.PROGRAMFILES || 'C:/Program Files', 'Google/Chrome/Application/chrome.exe')
        : '/usr/bin/google-chrome'),
    phpBinary: process.env.PHP_BINARY || 'php',
};
