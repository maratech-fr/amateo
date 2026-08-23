import { isValidElement, type ReactElement } from "react";
import { Navigate } from "react-router";
import { describe, expect, it } from "vitest";

import { routes } from "./router";

/**
 * NR du découpage en chunks (P4-6) — les FILETS, pas le contenu des pages.
 *
 * Découper est gratuit tant que ces garde-fous existent ; les perdre ne casse
 * aucun test de page, mais transforme un incident réseau banal en panne muette :
 *  - `errorElement` à la racine ET sur `AppLayout` : sans le second, l'échec du
 *    chunk d'UNE page démonte l'en-tête, la navigation et les bandeaux ;
 *  - `HydrateFallback` : sinon react-router rend `null` — page BLANCHE à chaque
 *    ouverture directe ou F5 d'une route lazy ;
 *  - le shell racine : il porte le retour d'attente de TOUTE navigation, zone
 *    non authentifiée comprise (le tunnel d'inscription y enchaîne ses étapes).
 */
describe("router — les filets du découpage", () => {
  const root = routes[0];

  it("la racine porte le shell d'attente, errorElement ET HydrateFallback", () => {
    expect(root.element).toBeDefined();
    expect(root.errorElement).toBeDefined();
    expect(root.HydrateFallback).toBeDefined();
  });

  it("toutes les routes de page vivent SOUS la racine (donc couvertes par les filets)", () => {
    // Une route ajoutée à côté de la racine échapperait aux deux filets sans que
    // rien ne le signale — c'est le mode de régression de ce découpage.
    expect(routes).toHaveLength(1);
    expect(root.children?.length).toBeGreaterThan(5);
  });

  it("le shell authentifié porte SON PROPRE errorElement (l'app survit à l'échec d'une page)", () => {
    const appLayout = findLayoutWithChildren(root, ["/planning", "/wizard"]);
    expect(appLayout, "le groupe de routes authentifiées est introuvable").toBeDefined();
    expect(appLayout?.errorElement).toBeDefined();
  });

  it("les gardes d'accès sont EAGER — nommément, pas par déduction", () => {
    // La version précédente de ce test filtrait les routes PARCE QU'elles avaient
    // un `element` : passer un garde en `lazy` le faisait sortir du filtre et le
    // test restait vert. On vise donc les routes par leur chemin.
    const admin = root.children?.find((r) => "/admin" === r.path);
    expect(admin?.element, "AdminGuard doit rester eager").toBeDefined();
    expect(admin?.lazy).toBeUndefined();

    const authed = findLayoutWithChildren(root, ["/planning", "/wizard"]);
    const guard = root.children?.find((r) => r.children?.includes(authed as never));
    expect(guard?.element, "AuthGuard doit rester eager").toBeDefined();
    expect(guard?.lazy).toBeUndefined();
  });

  it("la redirection des URL /admin inconnues ne dépend PAS du chunk de la console", () => {
    const admin = root.children?.find((r) => "/admin" === r.path);
    const catchAll = admin?.children?.find((r) => "*" === r.path);
    expect(catchAll, "le catch-all /admin doit être frère du shell, pas son enfant").toBeDefined();
    expect(catchAll?.element).toBeDefined();
  });

  // P5-14 — une URL inconnue n'est plus TÉLÉPORTÉE en silence (Navigate) : elle rend
  // une vraie 404. Le NR vise le mode de régression exact : quelqu'un « simplifie »
  // en remettant un <Navigate>, et l'écran 404 disparaît sans qu'aucun test de page
  // ne le voie.
  it("le catch-all APP rend un écran 404, pas un <Navigate> muet", () => {
    const authed = findLayoutWithChildren(root, ["/planning", "/wizard"]);
    const catchAll = authed?.children?.find((r) => "*" === r.path);
    expect(catchAll?.element, "le catch-all app doit avoir un element").toBeDefined();
    expect(isValidElement(catchAll?.element)).toBe(true);
    expect((catchAll?.element as ReactElement).type, "le catch-all app ne doit plus être un <Navigate>").not.toBe(Navigate);
  });

  it("le catch-all ADMIN rend un écran 404, pas un <Navigate> muet", () => {
    const admin = root.children?.find((r) => "/admin" === r.path);
    const catchAll = admin?.children?.find((r) => "*" === r.path);
    expect(catchAll?.element).toBeDefined();
    expect((catchAll?.element as ReactElement).type, "le catch-all admin ne doit plus être un <Navigate>").not.toBe(Navigate);
  });

  it("les pages lourdes sont bien LAZY (sinon le découpage ne sert à rien)", () => {
    const byPath = new Map(collect(root).map((r) => [r.path, r]));
    for (const path of ["/wizard", "/planning", "/matchs", "/club", "/doleances/:token"]) {
      expect(byPath.get(path)?.lazy, `${path} doit rester lazy`).toBeDefined();
    }
  });

  // RMM-1 PR2 — /matchs est désormais un LAYOUT (garde socle + nav) avec deux espaces
  // enfants : la boucle hebdo (index) et la Configuration. Les deux sont lazy (la
  // Configuration rare ne charge pas avec la boucle) et vivent SOUS les filets.
  it("le layout /matchs porte deux espaces lazy (boucle en index + configuration)", () => {
    const matches = collect(root).find((r) => "/matchs" === r.path);
    expect(matches?.lazy, "le layout /matchs doit être lazy").toBeDefined();
    const index = matches?.children?.find((r) => true === r.index);
    const config = matches?.children?.find((r) => "configuration" === r.path);
    expect(index?.lazy, "la boucle hebdo (index) doit être lazy").toBeDefined();
    expect(config?.lazy, "la Configuration (/matchs/configuration) doit être lazy").toBeDefined();
  });
});

type Route = (typeof routes)[number];

/** Le groupe de routes qui contient ces chemins (le shell authentifié). */
function findLayoutWithChildren(root: Route, paths: string[]): Route | undefined {
  return collect(root).find((r) => paths.every((p) => r.children?.some((c) => p === c.path)));
}

/** Aplatit l'arbre de routes. */
function collect(route: Route): Route[] {
  return [route, ...(route.children ?? []).flatMap(collect)];
}
