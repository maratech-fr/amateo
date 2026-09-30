import type { ReactNode } from "react";

import type { WizardStepId } from "./steps";

/**
 * Le texte d'aide de chaque étape de l'assistant, ouvert par le bouton (i) à gauche du titre
 * (`HelpButton`). Pure présentation — chaque affirmation a été confrontée au code (rang = priorité,
 * liens Mutualisation/Passerelle, niveau verrouillé d'une équipe engagée, onglets de Contraintes…).
 */
export const STEP_HELP_LABEL = "À quoi sert cette étape ?";
export const STEP_HELP_TRIGGER = "Comprendre cette étape";

export const WIZARD_STEP_HELP: Record<WizardStepId, ReactNode> = {
  teams: (
    <>
      <p>
        Tout part d'ici : le planning ne place que les équipes déclarées sur cet écran. Pour chacune, indiquez sa catégorie et son nombre de séances par semaine. Son rang dit qui passe
        en premier quand les créneaux manquent : les équipes du haut de la liste obtiennent les meilleurs horaires, celles du bas s'adaptent.
      </p>
      <p>Deux liens entre équipes changent beaucoup le résultat — ils se règlent avec le bouton «&nbsp;Liens&nbsp;» de chaque équipe :</p>
      <ul className="list-disc pl-5">
        <li>Mutualisation : deux équipes qui s'entraînent ensemble, sur le même créneau et dans le même gymnase (par exemple U13F2 et U13F3).</li>
        <li>Passerelle : des joueurs qui jouent dans deux équipes. Le planning évite alors que leurs entraînements se chevauchent, et leur laisse le temps de passer d'un gymnase à l'autre.</li>
      </ul>
      <p>Une équipe engagée en compétition a son niveau verrouillé, pour rester cohérente avec la saison.</p>
    </>
  ),
  venues: (
    <>
      <p>
        Le planning ne peut placer une séance que là où vous avez ouvert un créneau. Dessinez ici, gymnase par gymnase, les plages horaires dont le club dispose réellement, et indiquez
        si une salle peut accueillir plusieurs équipes en même temps.
      </p>
      <p>
        Localisez chaque gymnase avec son adresse exacte (numéro compris) : sa position sert à calculer les trajets. Si vos coachs ou vos joueurs passent d'un gymnase à l'autre dans la
        même soirée, ouvrez «&nbsp;Trajets entre gymnases&nbsp;» : le planning laissera alors le temps de s'y rendre.
      </p>
    </>
  ),
  coaches: (
    <>
      <p>
        Un planning n'est tenable que si les personnes le sont. Déclarez ici vos coachs et reliez-les à leurs équipes (coach principal ou adjoint), ainsi qu'aux équipes où ils jouent
        eux-mêmes.
      </p>
      <p>
        Grâce à ces liens, le planning évite qu'un coach soit attendu à deux endroits à la fois, qu'il entraîne pendant son propre entraînement de joueur, ou qu'il enchaîne deux gymnases
        sans le temps du trajet — en voiture s'il est véhiculé, à vélo sinon. Plus ces liens sont justes, moins vous aurez de corrections à faire à la main après la génération.
      </p>
    </>
  ),
  constraints: (
    <>
      <p>C'est ici que vous dites au planning ce qui compte pour votre club. Chaque onglet a son rôle :</p>
      <ul className="list-disc pl-5">
        <li>Base : les règles de fond, toujours appliquées (lecture seule).</li>
        <li>Bien-être : l'équilibre des semaines de chacun — jours consécutifs, trajets entre gymnases… — que vous pouvez régler.</li>
        <li>Horaires : «&nbsp;pas avant / pas après&nbsp;» une heure, par équipe.</li>
        <li>Jours : les jours autorisés ou à éviter.</li>
        <li>Gymnase : un gymnase imposé ou préféré.</li>
        <li>Dispo coach : les moments où un coach n'est pas disponible.</li>
        <li>Réserver : épingler une équipe sur un créneau précis — il sera toujours respecté.</li>
      </ul>
      <p>
        Chaque règle a un niveau. Obligatoire : le planning ne la franchira jamais, quitte à ne pas pouvoir tout placer. Préférée : il la respecte quand il peut, et vous dit où il a dû
        céder. Commencez souple : il est plus facile de durcir une règle que de comprendre pourquoi rien ne se place.
      </p>
    </>
  ),
  recap: (
    <p>
      Une dernière vérification avant de lancer le calcul. Cet écran rassemble ce que vous avez saisi et signale ce qui manque ou semble incohérent. Corriger ici prend une minute&nbsp;;
      découvrir le problème après la génération en coûte davantage.
    </p>
  ),
  generate: (
    <p>
      Le planning est calculé automatiquement à partir de tout ce que vous avez déclaré : il cherche la meilleure combinaison possible, pas seulement une combinaison qui fonctionne. À la
      fin, il vous indique ce qu'il n'a pas pu placer et les compromis qu'il a dû faire, pour que vous puissiez ajuster une règle ou déplacer une séance à la main. Rien n'est figé tant
      que vous n'avez pas validé.
    </p>
  ),
};
