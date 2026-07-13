const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

async function main() {
    const htmlPath = process.argv[2];
    const outputPath = process.argv[3];
    const options = JSON.parse(process.argv[4] || '{}');

    if (!htmlPath || !outputPath) {
        console.error('Usage: node render-pdf.js <htmlPath> <outputPath> [optionsJson]');
        process.exit(1);
    }

    const absoluteHtmlPath = path.resolve(htmlPath);

    if (!fs.existsSync(absoluteHtmlPath)) {
        console.error(`HTML file not found: ${absoluteHtmlPath}`);
        process.exit(1);
    }

    // Suppress crashpad handler — prevents SIGTRAP when AppArmor/profile
    // confines the Chromium binary (common on VPS/container deployments).
    process.env.BREAKPAD_DUMP_LOCATION = '/dev/null';

    const launchOptions = {
        headless: true,
        args: options.args || [],
    };

    // Prepend crashpad-suppressing flags so they appear before any
    // user-supplied args (last occurrence wins for most Chromium flags).
    launchOptions.args = [
        '--disable-crashpad-for-testing',
        '--disable-features=Crashpad',
        ...launchOptions.args,
    ];

    if (options.executablePath) {
        launchOptions.executablePath = options.executablePath;
    }

    const browser = await chromium.launch(launchOptions);

    try {
        const page = await browser.newPage();

        const fileUrl = 'file://' + absoluteHtmlPath;

        const navigationTimeoutMs = (options.timeout ?? 300) * 1000;

        await page.goto(fileUrl, {
            waitUntil: options.waitUntil || 'networkidle',
            timeout: navigationTimeoutMs,
        });

        const pdfOptions = {
            path: outputPath,
            printBackground: options.printBackground ?? true,
            displayHeaderFooter: options.displayHeaderFooter ?? false,
        };

        if (options.format) {
            pdfOptions.format = options.format;
        }

        if (options.width && options.height) {
            pdfOptions.width = options.width;
            pdfOptions.height = options.height;
        }

        if (options.margin) {
            pdfOptions.margin = options.margin;
        }

        if (options.headerTemplate) {
            pdfOptions.headerTemplate = options.headerTemplate;
            pdfOptions.displayHeaderFooter = true;
        }

        if (options.footerTemplate) {
            pdfOptions.footerTemplate = options.footerTemplate;
            pdfOptions.displayHeaderFooter = true;
        }

        await page.pdf(pdfOptions);
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
