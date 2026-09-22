import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * A17 config-regression guard that runs in CI (the runtime e2e can't — CI drives
 * Playwright against the Vite dev server, which serves no nginx headers). Reads
 * the nginx configs directly and asserts the security headers + CSP directives
 * are present and correctly scoped, so removing a header or a `blob:` allowance
 * fails a fast unit test instead of silently shipping.
 */
const root = resolve(process.cwd(), "..");
const read = (p: string): string => readFileSync(resolve(root, p), "utf8");

/** Directive lines only — drop `#` comments so prose mentioning a header/CSP token never matches. */
const directivesOf = (conf: string): string =>
  conf
    .split("\n")
    .map((l) => l.trim())
    .filter((l) => l.length > 0 && !l.startsWith("#"))
    .join("\n");

const base = directivesOf(read("docker/frontend/security-headers.conf"));
const cspConf = directivesOf(read("docker/frontend/csp.conf"));
const frontendConf = read("docker/frontend/nginx.conf");
const backendConf = directivesOf(read("docker/nginx/default.conf"));

/** The single `Content-Security-Policy "…"` value from csp.conf. */
const cspValue = /Content-Security-Policy\s+"([^"]+)"/.exec(cspConf)?.[1] ?? "";
const cspDirective = (name: string): string => new RegExp(`(?:^|;)\\s*${name}\\s+([^;]*)`).exec(cspValue)?.[1]?.trim() ?? "";

describe("A17 baseline security headers (docker/frontend/security-headers.conf)", () => {
  it("declares the four baseline headers", () => {
    expect(base).toMatch(/add_header\s+X-Frame-Options\s+"DENY"/);
    expect(base).toMatch(/add_header\s+X-Content-Type-Options\s+"nosniff"/);
    expect(base).toMatch(/add_header\s+Referrer-Policy\s+"same-origin"/);
    expect(base).toMatch(/add_header\s+Strict-Transport-Security\s+"max-age=\d+"/);
  });

  it("keeps HSTS without includeSubDomains and carries no CSP", () => {
    expect(base).not.toMatch(/includeSubDomains/);
    expect(base).not.toMatch(/Content-Security-Policy/);
  });

  it("keeps the whole app out of search indexes via X-Robots-Tag noindex (P5-18)", () => {
    // Single home for the app's non-indexing: one `add_header` on the shared
    // baseline covers EVERY app response — SPA, in-app privacy page, and the
    // token pages (club-approval / doléances) whose paths must never be named
    // in the public robots.txt. Dropping this line silently re-exposes the app
    // (and any forwarded token URL) to indexing.
    expect(base).toMatch(/add_header\s+X-Robots-Tag\s+"[^"]*noindex[^"]*"/);
  });
});

describe("A17 CSP (docker/frontend/csp.conf)", () => {
  it("locks default/script/object and blocks framing", () => {
    expect(cspDirective("default-src")).toBe("'self'");
    // P5-3b — script-src reste verrouillé à 'self' + LA seule exception d'hôte tiers
    // posée d'office : le loader Cloudflare Turnstile. Aucun autre hôte n'est admis.
    expect(cspDirective("script-src")).toBe("'self' https://challenges.cloudflare.com");
    expect(cspDirective("object-src")).toBe("'none'");
    expect(cspDirective("frame-ancestors")).toBe("'none'");
    expect(cspDirective("base-uri")).toBe("'self'");
  });

  it("allows the exact relaxations the app needs (and no more)", () => {
    expect(cspDirective("style-src")).toBe("'self' 'unsafe-inline'"); // React inline styles
    expect(cspDirective("img-src")).toContain("blob:"); // logo cropper preview / palette
    expect(cspDirective("connect-src")).toContain("blob:"); // logo recrop fetch()
    // P5-3b — l'iframe du challenge Turnstile (script-src pour le loader, frame-src
    // pour l'iframe) : les deux directives portent le même hôte, et lui seul.
    expect(cspDirective("frame-src")).toBe("'self' https://challenges.cloudflare.com");
    expect(cspDirective("script-src")).not.toContain("unsafe-inline"); // bundled module only
    expect(cspValue).not.toContain("unsafe-eval");
  });
});

describe("A17 wiring (nginx.conf)", () => {
  it("adds the baseline at the frontend server level (covers /api, /exports, nginx errors)", () => {
    // Everything before the first `location …{` block is server-level config.
    const serverPrefix = frontendConf.slice(0, frontendConf.search(/\n\s*location\s/));
    expect(serverPrefix).toContain("include /etc/nginx/snippets/security-headers.conf;");
  });

  it("scopes the CSP to HTML only — never onto the /api or /exports proxies", () => {
    expect(frontendConf).toContain("include /etc/nginx/snippets/csp.conf;");
    const apiBlock = /location \/api\/ \{[^}]*\}/s.exec(frontendConf)?.[0] ?? "";
    const exportsBlock = /location \/exports\/ \{[^}]*\}/s.exec(frontendConf)?.[0] ?? "";
    expect(apiBlock).not.toContain("csp.conf");
    expect(exportsBlock).not.toContain("csp.conf");
  });

  it("backend nginx sets no security header (single source = the frontend edge)", () => {
    expect(backendConf).not.toMatch(/add_header\s+X-Frame-Options/);
    expect(backendConf).not.toMatch(/add_header\s+Content-Security-Policy/);
  });
});
