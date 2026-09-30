import type { ReactNode } from "react";

/**
 * Le texte d'aide de chaque écran du module Matchs, ouvert par le bouton (i) à gauche de la barre
 * d'onglets (`HelpButton`, réutilisé de l'assistant). Pure présentation — chaque affirmation a été
 * confrontée au code (compteur « FBI à faire » du Calendrier, réimport qui remonte les écarts sans
 * écraser un placement, durée match+échauffement de la Configuration, trajet MANUAL jamais écrasé…).
 */
export const MATCHES_HELP_LABEL = "À quoi sert cet écran ?";
export const MATCHES_HELP_TRIGGER = "Comprendre cet écran";

export type MatchesTab = "conflits" | "calendrier" | "importer" | "configuration" | "adversaires" | "semaine-type" | "contraintes";

/** L'onglet actif, dérivé du chemin (le Calendrier est la racine `/matchs`). */
export function activeMatchesTab(pathname: string): MatchesTab {
  if (pathname.startsWith("/matchs/conflits")) return "conflits";
  if (pathname.startsWith("/matchs/importer")) return "importer";
  if (pathname.startsWith("/matchs/configuration")) return "configuration";
  if (pathname.startsWith("/matchs/adversaires")) return "adversaires";
  if (pathname.startsWith("/matchs/semaine-type")) return "semaine-type";
  if (pathname.startsWith("/matchs/contraintes")) return "contraintes";
  return "calendrier";
}

export const MATCHES_TAB_HELP: Record<MatchesTab, ReactNode> = {
  calendrier: (
    <p>
      Votre écran de travail de la saison. Chaque week-end, vous y voyez les matchs à domicile à placer, ceux déjà posés, et les déplacements. «&nbsp;Placer automatiquement&nbsp;»
      cherche pour vous le meilleur créneau et le meilleur gymnase de chaque match, en respectant les règles de la ligue, celles du club et les disponibilités des coachs. Vous gardez la
      main : chaque match peut être déplacé ou posé à la main. Le compteur «&nbsp;FBI à faire&nbsp;» rappelle ce qui reste à reporter dans FBI, le portail de la fédération.
    </p>
  ),
  conflits: (
    <p>
      Tout ce qui pose problème sur la saison, au même endroit : un match hors des horaires autorisés par la ligue, un coach attendu à deux endroits en même temps, un gymnase interdit
      pour une équipe, une salle déjà occupée… Chaque conflit dit ce qui coince et vous emmène au match concerné. Un conflit n'est pas forcément une erreur : c'est parfois un choix que
      vous assumez, mais il est préférable de le faire en connaissance de cause.
    </p>
  ),
  importer: (
    <p>
      C'est ici qu'entrent les matchs de la saison : déposez l'export FBI de vos rencontres, ou récupérez les amicaux et les coupes auprès de la fédération. Chaque rencontre est
      rattachée à la bonne équipe et au bon gymnase ; ce que l'app ne sait pas relier seule vous est présenté dans une file de traitement, à confirmer une fois pour toutes. Réimporter
      plus tard met à jour les matchs sans écraser ce que vous avez déjà placé.
    </p>
  ),
  configuration: (
    <p>
      Les réglages que l'on fait une fois par saison : la durée des matchs par catégorie (échauffement compris), les gymnases ouverts aux matchs et leurs horaires, les échéances de la
      ligue. Ce sont les fondations du placement : une durée fausse ou un gymnase mal ouvert se répercutent sur tous les week-ends.
    </p>
  ),
  adversaires: (
    <p>
      Où joue chaque club adverse, et combien de temps il faut pour s'y rendre depuis votre club. Ces trajets servent à organiser les déplacements et à fixer l'heure de départ. Appariez
      chaque adversaire à sa salle ; si un temps vous paraît faux, corrigez-le : votre correction ne sera jamais écrasée.
    </p>
  ),
  "semaine-type": (
    <p>
      Le week-end idéal de votre club, dessiné une fois : pour chaque équipe, le créneau qu'elle recevrait «&nbsp;si elle avait le choix&nbsp;» (jour, heure, gymnase). C'est une aide,
      pas une obligation : c'est la fédération qui décide qui reçoit chaque week-end, et le placement automatique s'approche de ce modèle autant qu'il le peut. Si votre club raisonne en
      deux semaines qui alternent (A et B), cochez l'option : chaque créneau porte alors sa semaine.
    </p>
  ),
  contraintes: (
    <>
      <p>Les règles que le placement doit suivre, réunies au même endroit :</p>
      <ul className="list-disc pl-5">
        <li>Ligue : les plages horaires imposées par votre comité ou votre ligue.</li>
        <li>Club : les règles de votre club («&nbsp;pas de match le dimanche après 16h&nbsp;»…).</li>
        <li>Équipes : un gymnase interdit pour une équipe.</li>
        <li>Coachs : les moments où un coach n'est pas disponible.</li>
      </ul>
      <p>
        Une règle obligatoire n'est jamais franchie par le placement automatique ; une règle préférée est respectée quand c'est possible. Vous pouvez toujours poser un match à la main
        hors d'une règle : il sera signalé dans les Conflits.
      </p>
    </>
  ),
};
