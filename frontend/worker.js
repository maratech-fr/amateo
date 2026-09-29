import http from 'http';
import fs from 'fs';
import path from 'path';
import puppeteer from 'puppeteer';
import { startHeartbeat } from './worker-heartbeat.js';

const PORT = 3000;
const OUTPUT_DIR = '/app/backend/public/exports';

// A4 in CSS px at 96dpi. Landscape swaps the two. ~24px of margin each way.
const A4 = { w: 794, h: 1123 };
const MARGIN = 24;
// Bottom margin enlarged when a brand footer is seated on every page (P5-24) : the
// "Généré avec …" template lives in the bottom margin, so it needs more room there than
// the plain 24px. availH shrinks by the same amount so the grid never spills onto page 2.
const FOOTER_MARGIN = 40;

async function generateFiles(html, filename, landscape, multiSection, footerTemplate) {
  fs.mkdirSync(OUTPUT_DIR, { recursive: true });

  const pageW = landscape ? A4.h : A4.w;
  const pageH = landscape ? A4.w : A4.h;
  // The bottom margin grows to seat the footer; the top/left/right stay at MARGIN. The
  // usable content height must subtract the REAL margins (top + bottom), otherwise the
  // grid is fitted to a taller box than the print actually leaves and spills to page 2.
  const bottomMargin = footerTemplate ? FOOTER_MARGIN : MARGIN;
  const availW = pageW - 2 * MARGIN;
  const availH = pageH - MARGIN - bottomMargin;

  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });

  try {
    const page = await browser.newPage();
    // Lay the content out at the printable width so the measured size reflects
    // the real page.
    await page.setViewport({ width: availW, height: availH });
    await page.setContent(html, { waitUntil: 'networkidle0' });

    const geom = { pageW, pageH, availW, availH, bottomMargin, footerTemplate };
    if (multiSection) {
      await renderMultiSection(page, filename, landscape, geom);
    } else {
      await renderSinglePage(page, filename, landscape, geom);
    }
  } finally {
    await browser.close();
  }
}

// The brand footer wired into every page (P5-24). When the backend passes a
// `footerTemplate`, Puppeteer draws it in the bottom margin of EACH page; an empty
// header template suppresses Chromium's default date/url header.
function footerOptions(footerTemplate) {
  if (!footerTemplate) {
    return {};
  }
  return {
    displayHeaderFooter: true,
    headerTemplate: '<div></div>',
    footerTemplate,
  };
}

// Historical one-page export (unchanged): the whole document is scaled so the
// WHOLE week fits ONE A4 page, and the PNG frames the same single page. Every
// single-section call takes this path and gets exactly the file it got before.
async function renderSinglePage(page, filename, landscape, { pageW, pageH, availW, availH, bottomMargin, footerTemplate }) {
  const { contentH, contentW } = await page.evaluate(() => ({
    contentH: document.documentElement.scrollHeight,
    contentW: document.documentElement.scrollWidth,
  }));
  // Shrink as far as needed so the WHOLE week fits one page (font gets small
  // rather than any gym/row being dropped) — a multi-page training plan is not
  // useful. No floor: fitting everything beats truncating.
  const scale = Math.min(1, availH / contentH, availW / contentW);

  // PDF — single A4 page, given scale, no auto-pagination overflow.
  // SEC-14 : le filename vient de la requête — basename() interdit la
  // traversée (../../) même si l'appelant interne était compromis.
  // nosemgrep: javascript.lang.security.audit.path-traversal.path-join-resolve-traversal.path-join-resolve-traversal -- basename() interdit la traversée
  const pdfPath = path.join(OUTPUT_DIR, path.basename(filename));
  await page.pdf({
    path: pdfPath,
    format: 'A4',
    landscape,
    printBackground: true,
    scale,
    pageRanges: '1',
    margin: { top: `${MARGIN}px`, bottom: `${bottomMargin}px`, left: `${MARGIN}px`, right: `${MARGIN}px` },
    ...footerOptions(footerTemplate),
  });

  // PNG — same one-page framing: scale the body, shoot the page rectangle.
  await page.evaluate((s) => {
    document.body.style.transformOrigin = 'top left';
    document.body.style.transform = `scale(${s})`;
  }, scale);
  await page.setViewport({ width: pageW, height: pageH });
}

// Multi-section export : section 1 (`.page-grid`) is the manager grid — fitted to
// page 1 exactly like the single-page path — and section 2 (`.page-matrix`, forced
// onto a new page by CSS `break-before: page`) is the team × day matrix, which
// flows onto page(s) 2+ at natural size.
async function renderMultiSection(page, filename, landscape, { pageW, pageH, availW, availH, bottomMargin, footerTemplate }) {
  // Measure the GRID section AND the header above it : page 1 carries the document
  // header (club title bar) THEN the grid, and both must fit one page. Measuring the
  // grid alone let the header's height spill the grid's tail onto page 2.
  const m = await page.evaluate(() => {
    // The 24px print margins already inset the page; drop the body padding so the
    // measurement is against exactly one printable page.
    document.body.style.padding = '0';
    const el = document.querySelector('.page-grid');
    const rect = el ? el.getBoundingClientRect() : null;
    return {
      // gridTop = everything above the grid on page 1 (header + its margin).
      gridTop: rect ? rect.top : 0,
      gridH: el ? el.scrollHeight : 0,
      gridW: el ? el.scrollWidth : 0,
    };
  });
  const gridH = m.gridH || availH;
  const gridW = m.gridW || availW;
  // Fit the grid in the room LEFT under the header on one printable page. A small inset
  // keeps it clear of the page boundary so sub-pixel rounding never spills a sliver onto
  // page 2 (that spill was the failure mode of a transform+overflow box : `transform`
  // does NOT reflow, so `overflow: hidden` cannot clip across a print page break). `zoom`
  // DOES reflow, so the grid's box actually shrinks and the matrix's `break-before: page`
  // opens page 2.
  const FIT_INSET = 6;
  const usableH = availH - FIT_INSET - m.gridTop;
  // Print row heights round slightly differently from the screen measurement, and the
  // error accumulates over many rows; a small proportional margin keeps a tall grid off
  // the page-2 boundary whatever its row count (the same "font gets a bit smaller rather
  // than a row spills" trade-off the single-page path already makes).
  const SAFETY = 0.96;
  const scale = Math.min(1, (usableH / gridH) * SAFETY, availW / gridW);

  await page.evaluate((s) => {
    const el = document.querySelector('.page-grid');
    if (el) {
      el.style.zoom = String(s);
    }
  }, scale);

  // PDF — natural pagination (no pageRanges, no global scale) : the grid is already
  // fitted in page 1, the matrix flows onto the following page(s), its rank groups
  // kept whole by `break-inside: avoid`.
  // nosemgrep: javascript.lang.security.audit.path-traversal.path-join-resolve-traversal.path-join-resolve-traversal -- basename() interdit la traversée
  const pdfPath = path.join(OUTPUT_DIR, path.basename(filename));
  await page.pdf({
    path: pdfPath,
    format: 'A4',
    landscape,
    printBackground: true,
    margin: { top: `${MARGIN}px`, bottom: `${bottomMargin}px`, left: `${MARGIN}px`, right: `${MARGIN}px` },
    ...footerOptions(footerTemplate),
  });
}

const server = http.createServer(async (req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok' }));
    return;
  }

  if (req.method === 'POST' && req.url === '/generate') {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', async () => {
      try {
        const { html, filename, landscape, multiSection, footerTemplate } = JSON.parse(body);

        if (!html || !filename) {
          res.writeHead(400, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ success: false, error: 'Missing html or filename' }));
          return;
        }

        await generateFiles(
          html,
          filename,
          landscape === true,
          multiSection === true,
          typeof footerTemplate === 'string' ? footerTemplate : '',
        );

        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: true }));
      } catch (err) {
        console.error('Generation error:', err);
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: false, error: err.message }));
      }
    });
    return;
  }

  res.writeHead(404);
  res.end();
});

function startWorker() {
  // Heartbeat admin — module builtins-only (aucune dépendance npm dans ce conteneur).
  startHeartbeat();

  server.listen(PORT, () => {
    console.log(`pdf-worker listening on port ${PORT}`);
  });
}

if (process.argv[1]?.endsWith('worker.js')) {
  startWorker();
}
