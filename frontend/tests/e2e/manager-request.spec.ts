import { CLUB_TEXTS } from "../../src/features/club/texts";
import { SESSION_TEXTS } from "../../src/shared/session/texts";
import { expect, test, type Page } from "./fixtures";
import { registerAndVerify, uniqueAra } from "./support";

/**
 * **Un Membre demande à devenir gestionnaire ; un gestionnaire l'accepte — de bout en bout, dans
 * deux navigateurs.** (Chantier Membre PR 3.)
 *
 * Ce que seul ce niveau prouve : le GESTE réel des deux côtés (la carte « Mon rôle » du Membre, la
 * pastille et le bouton « Accepter la demande de … » du gestionnaire) et la bascule visible de
 * l'écran du Membre une fois promu. Les règles serveur (une demande à la fois, seaux, club sans
 * gestionnaire, adresse officielle, audit) sont prouvées par `ManagerRoleRequestTest` (PHPUnit) ;
 * les états de la carte par `ClubPage.test.tsx` (Vitest) — jamais rejoués ici.
 *
 * Témoin de promotion : la section « Membres » (réservée aux gestionnaires) apparaît sur `/club`
 * et la carte « Mon rôle » disparaît. (Le plan disait « Générer visible » : sur un club NEUF,
 * l'étape Génération est bloquée tant que la configuration est incomplète — le bouton n'y serait
 * jamais, promu ou pas, et le témoin mentirait.)
 *
 * Décor créé par le test via l'API (inscription → vérification → approbation de l'adhésion par le
 * gestionnaire) ; ARA unique par run, la base n'est jamais réinitialisée.
 */

async function memberJoins(page: Page, ara: string, email: string): Promise<void> {
  await registerAndVerify(page, { email, ara, firstName: "Léa", lastName: "Demande" });
}

async function approvePendingAsMember(managerPage: Page, email: string): Promise<void> {
  const pending = await managerPage.request.get("/api/memberships/pending");
  expect(pending.ok()).toBe(true);
  const members = ((await pending.json()) as { members: { id: string; email: string }[] }).members;
  const target = members.find((m) => m.email === email);
  expect(target, "l'adhésion du Membre est en attente").toBeDefined();
  const approved = await managerPage.request.post(`/api/memberships/${target?.id}/approve`, { data: { role: "member" } });
  expect(approved.ok()).toBe(true);
}

test("un Membre demande, le gestionnaire accepte : le Membre devient gestionnaire ; puis une demande s'annule", async ({ browser }) => {
  test.setTimeout(180_000);
  const ara = uniqueAra("MRQ");
  const stamp = Date.now().toString(36);
  const managerEmail = `marc-${stamp}@e2e.test`;
  const memberEmail = `lea-${stamp}@e2e.test`;

  const managerContext = await browser.newContext();
  const memberContext = await browser.newContext();
  try {
    const manager = await managerContext.newPage();
    const member = await memberContext.newPage();
    await registerAndVerify(manager, { email: managerEmail, ara, firstName: "Marc", lastName: "Gestion", clubName: `Club ${ara}` });
    await memberJoins(member, ara, memberEmail);
    await approvePendingAsMember(manager, memberEmail);

    // --- Le Membre demande depuis sa carte « Mon rôle ».
    await member.goto("/club");
    await member.getByRole("button", { name: CLUB_TEXTS.requestManagerRole }).click();
    // UXS-20 : la demande se confirme avant de partir.
    await member.getByRole("dialog", { name: SESSION_TEXTS.requestManagerRoleConfirmTitle }).getByRole("button", { name: SESSION_TEXTS.requestManagerRoleConfirmLabel }).click();
    await expect(member.getByText(SESSION_TEXTS.managerRequestSent)).toBeVisible();
    await expect(member.getByRole("button", { name: CLUB_TEXTS.cancelManagerRequest })).toBeVisible();

    // --- Le gestionnaire voit la pastille et accepte.
    await manager.goto("/club");
    await manager.getByRole("button", { name: new RegExp(`^${CLUB_TEXTS.sectionMembers}`) }).click();
    await expect(manager.getByText(/Demande gestionnaire/)).toBeVisible();
    await manager.getByRole("button", { name: CLUB_TEXTS.acceptManagerRequestOf("Léa Demande") }).click();
    // UXS-20 (Q2) : « Accepter » donne tous les droits — il se confirme.
    await manager.getByRole("dialog", { name: CLUB_TEXTS.acceptManagerRequestTitle("Léa Demande") }).getByRole("button", { name: CLUB_TEXTS.acceptManagerRequest, exact: true }).click();
    await expect(manager.getByText(/Demande gestionnaire/)).toHaveCount(0);

    // --- Le Membre, rechargé, est gestionnaire : la section « Membres » lui est ouverte.
    await member.reload();
    await expect(member.getByRole("button", { name: new RegExp(`^${CLUB_TEXTS.sectionMembers}`) })).toBeVisible({ timeout: 20_000 });
    await expect(member.getByRole("button", { name: CLUB_TEXTS.requestManagerRole })).toHaveCount(0);

    // --- Annulation : un second Membre demande puis annule ; la pastille disparaît côté gestionnaire.
    const otherContext = await browser.newContext();
    try {
      const other = await otherContext.newPage();
      const otherEmail = `kim-${stamp}@e2e.test`;
      await registerAndVerify(other, { email: otherEmail, ara, firstName: "Kim", lastName: "Annule" });
      await approvePendingAsMember(manager, otherEmail);
      await other.goto("/club");
      await other.getByRole("button", { name: CLUB_TEXTS.requestManagerRole }).click();
      await other.getByRole("dialog", { name: SESSION_TEXTS.requestManagerRoleConfirmTitle }).getByRole("button", { name: SESSION_TEXTS.requestManagerRoleConfirmLabel }).click();
      // « Annuler ma demande » reste à un clic (geste inverse, réversible) : aucune confirmation.
      await other.getByRole("button", { name: CLUB_TEXTS.cancelManagerRequest }).click();
      await expect(other.getByText(SESSION_TEXTS.managerRequestCancelled)).toBeVisible();
      await expect(other.getByRole("button", { name: CLUB_TEXTS.requestManagerRole })).toBeVisible();

      await manager.reload();
      await manager.getByRole("button", { name: new RegExp(`^${CLUB_TEXTS.sectionMembers}`) }).click();
      await expect(manager.getByRole("button", { name: CLUB_TEXTS.acceptManagerRequestOf("Kim Annule") })).toHaveCount(0);
    } finally {
      await otherContext.close();
    }
  } finally {
    await managerContext.close();
    await memberContext.close();
  }
});
