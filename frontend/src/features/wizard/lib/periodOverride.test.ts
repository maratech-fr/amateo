import { describe, expect, it } from "vitest";

import type { Team, TeamPeriodOverride } from "../api";
import { effectiveMaxPerDay, periodOverrideBody } from "./periodOverride";

/**
 * PROMESSE : la surcharge de période d'une équipe est un REMPLACEMENT COMPLET (`PUT`, `null` = saison,
 * `TeamPeriodOverrideStateProcessor`) — un geste sur l'un de ses champs (active, séances, séances max
 * par jour) n'efface jamais les deux autres, et la ligne ne disparaît que quand TOUT est revenu à la
 * valeur de saison. Avant ce lot, `PeriodTeams` reconstruisait le corps à la main sans le plafond :
 * décocher puis recocher une équipe effaçait son plafond de période en silence.
 * `periodOverrideBody` rend le corps à écrire, ou `null` quand il n'y a plus rien à surcharger (la
 * ligne existante se supprime, aucune ne se crée). `effectiveMaxPerDay` est la valeur que le repère
 * affiche : celle de la période si elle est posée, sinon celle de la saison.
 * Jumeau écran : `steps/PeriodTeams.test.tsx` (le même geste, à l'écran).
 */

const team = (patch: Partial<Team> = {}): Team =>
  ({ id: "t1", name: "U15", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true, maxSessionsPerDay: 2, ...patch }) as Team;

const override = (patch: Partial<TeamPeriodOverride> = {}): TeamPeriodOverride =>
  ({ id: "o1", schedulePlanId: "plan-1", teamId: "t1", isActive: true, sessionsPerWeek: null, maxSessionsPerDay: 3, source: null, ...patch }) as TeamPeriodOverride;

describe("periodOverrideBody — un champ changé ne perd jamais les autres", () => {
  it("décocher une équipe à plafond de période garde son plafond", () => {
    expect(periodOverrideBody("plan-1", team(), override(), { isActive: false })).toEqual({
      schedulePlanId: "plan-1",
      teamId: "t1",
      isActive: false,
      sessionsPerWeek: null,
      maxSessionsPerDay: 3,
    });
  });

  it("recocher une équipe en pause garde son plafond de période (la ligne n'est pas supprimée)", () => {
    expect(periodOverrideBody("plan-1", team(), override({ isActive: false }), { isActive: true })).toEqual({
      schedulePlanId: "plan-1",
      teamId: "t1",
      isActive: true,
      sessionsPerWeek: null,
      maxSessionsPerDay: 3,
    });
  });

  it("remettre les séances de saison garde le plafond de période", () => {
    expect(periodOverrideBody("plan-1", team(), override({ sessionsPerWeek: 4 }), { sessionsPerWeek: null })).toEqual({
      schedulePlanId: "plan-1",
      teamId: "t1",
      isActive: true,
      sessionsPerWeek: null,
      maxSessionsPerDay: 3,
    });
  });

  it("changer le plafond garde l'état et les séances de la période", () => {
    expect(periodOverrideBody("plan-1", team(), override({ isActive: false, sessionsPerWeek: 4, maxSessionsPerDay: null }), { maxSessionsPerDay: 3 })).toEqual({
      schedulePlanId: "plan-1",
      teamId: "t1",
      isActive: false,
      sessionsPerWeek: 4,
      maxSessionsPerDay: 3,
    });
  });

  it("sans ligne existante, poser un plafond de période crée une ligne active aux séances de saison", () => {
    expect(periodOverrideBody("plan-1", team(), undefined, { maxSessionsPerDay: 3 })).toEqual({
      schedulePlanId: "plan-1",
      teamId: "t1",
      isActive: true,
      sessionsPerWeek: null,
      maxSessionsPerDay: 3,
    });
  });
});

describe("periodOverrideBody — la ligne ne disparaît que quand tout est revenu à la saison", () => {
  it("rendre le plafond à la saison (null) sur une ligne qui ne tenait que lui : rien à écrire", () => {
    expect(periodOverrideBody("plan-1", team(), override(), { maxSessionsPerDay: null })).toBeNull();
  });

  it("revenir à la valeur de saison (2) vaut la saison : rien à écrire", () => {
    expect(periodOverrideBody("plan-1", team(), override(), { maxSessionsPerDay: 2 })).toBeNull();
  });

  it("rendre le plafond à la saison sur une équipe en pause garde la ligne (la pause la tient)", () => {
    expect(periodOverrideBody("plan-1", team(), override({ isActive: false }), { maxSessionsPerDay: null })).toEqual({
      schedulePlanId: "plan-1",
      teamId: "t1",
      isActive: false,
      sessionsPerWeek: null,
      maxSessionsPerDay: null,
    });
  });

  it("sans ligne existante, un geste qui reste à la saison n'écrit rien", () => {
    expect(periodOverrideBody("plan-1", team(), undefined, { isActive: true })).toBeNull();
  });
});

describe("effectiveMaxPerDay — la valeur que la période applique", () => {
  it("sans surcharge : la valeur de saison", () => {
    expect(effectiveMaxPerDay(team({ maxSessionsPerDay: 2 }), undefined)).toBe(2);
  });

  it("surcharge sans plafond (null = saison) : la valeur de saison", () => {
    expect(effectiveMaxPerDay(team({ maxSessionsPerDay: 2 }), override({ maxSessionsPerDay: null }))).toBe(2);
  });

  it("surcharge à 3 : la valeur de la période", () => {
    expect(effectiveMaxPerDay(team({ maxSessionsPerDay: 2 }), override({ maxSessionsPerDay: 3 }))).toBe(3);
  });
});
