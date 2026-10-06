import { Link } from "react-router";

import { PageHeader } from "@/shared/components/ui/page-header";
import { LEGAL_NOTICE_URL, PRODUCT_NAME, PUBLISHER_NAME } from "@/shared/lib/product";
import { useAuthStore } from "@/shared/stores/authStore";

import { TERMS_VERSION } from "./terms";

/**
 * RGPD — politique de confidentialité + CGU. STRUCTURE définitive, TEXTES
 * provisoires : chaque section marquée « À COMPLÉTER » attend la rédaction
 * finale (fondateur/juriste) avant la commercialisation. La page est publique
 * (liée depuis le register — le consentement pointe ici).
 */
const PLACEHOLDER = "— À COMPLÉTER (rédaction finale fondateur/juriste avant commercialisation) —";

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="space-y-2">
      <h2 className="text-base font-semibold">{title}</h2>
      <div className="space-y-2 text-sm text-muted-foreground">{children}</div>
    </section>
  );
}

export function PrivacyPage() {
  // Page publique : « Signaler » exige une session (POST /feedback authentifié), on ne le montre
  // donc qu'à un visiteur connecté — le titre, lui, reste aligné pour tous.
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  return (
    <div className="mx-auto max-w-2xl space-y-6 px-4 py-10">
      <PageHeader
        title="Politique de confidentialité & conditions d'utilisation"
        screen="/confidentialite"
        subtitle={`Version ${TERMS_VERSION} (provisoire)`}
        showFeedback={isAuthenticated}
      />

      <Section title="1. Qui sommes-nous (responsable de traitement)">
        <p>
          <strong>{PRODUCT_NAME}</strong>, édité par <strong>{PUBLISHER_NAME}</strong>, est un service de
          planification d'entraînements pour clubs sportifs. Pour les données de votre <strong>compte</strong>{" "}
          (identité, email), {PUBLISHER_NAME} est responsable de traitement. Pour les données que votre{" "}
          <strong>club</strong> saisit (coachs, équipes, plannings), le club est responsable de traitement et{" "}
          {PUBLISHER_NAME} agit comme sous-traitant.
        </p>
        <p>{PLACEHOLDER}</p>
      </Section>

      <Section title="2. Données collectées et finalités">
        <ul className="list-disc space-y-1 pl-5">
          <li>Compte gestionnaire : prénom, nom, email, mot de passe (haché) — authentification et gestion du club.</li>
          <li>Données du club : coachs (nom, email, téléphone), équipes, gymnases, contraintes — génération des plannings.</li>
          <li>Contacts officiels FFBB (président, correspondant) : données publiées par la fédération — organisation des rencontres (base légale : intérêt légitime).</li>
        </ul>
        <p>{PLACEHOLDER}</p>
      </Section>

      <Section title="3. Durées de conservation">
        <ul className="list-disc space-y-1 pl-5">
          <li>Comptes inactifs : préavis à 23 mois, anonymisation à 24 mois.</li>
          <li>Saisons : la saison courante et la précédente sont conservées ; les plus anciennes sont purgées automatiquement.</li>
          <li>Journal d'audit : 12 mois.</li>
          <li>Comptes jamais vérifiés : 7 jours.</li>
          <li>Sauvegardes : purge naturelle par rotation (30 jours) — aucune restauration sélective de données effacées.</li>
        </ul>
      </Section>

      <Section title="4. Vos droits">
        <ul className="list-disc space-y-1 pl-5">
          <li><strong>Accès / portabilité</strong> : exportez vos données (page Profil) et celles du club (Gestion du club) au format JSON.</li>
          <li><strong>Rectification</strong> : modifiez vos informations dans la page Profil.</li>
          <li><strong>Effacement</strong> : supprimez votre compte depuis la page Profil (anonymisation immédiate ; sans autre membre actif, les données du club sont supprimées après 30 jours).</li>
          <li><strong>Opposition</strong> (contacts FFBB) : {PLACEHOLDER}</li>
        </ul>
        <p>Les coachs et membres saisis par un club exercent leurs droits auprès de leur club, responsable de traitement de ces données.</p>
      </Section>

      <Section title="5. Sous-traitance (DPA)">
        <p>{PLACEHOLDER}</p>
      </Section>

      <Section title="6. Hébergement et sécurité">
        <p>
          Données hébergées dans l'Union européenne. Isolation stricte entre clubs (contrôle applicatif et au niveau de
          la base de données), chiffrement des mots de passe, journal d'audit inviolable.
        </p>
        <p>{PLACEHOLDER}</p>
      </Section>

      <Section title="7. Contact">
        <p>{PLACEHOLDER}</p>
        <p>
          Éditeur, responsable de la publication et hébergeur : voir les{" "}
          <a className="text-accent hover:underline" href={LEGAL_NOTICE_URL} target="_blank" rel="noreferrer noopener">
            mentions légales
          </a>
          .
        </p>
      </Section>

      <Section title="8. Mesure d'audience (vitrine)">
        <p>
          Notre page de vente publique (la vitrine <strong>{PRODUCT_NAME}</strong>, hors application) utilise{" "}
          <strong>Umami</strong>, une solution de mesure d'audience auto-hébergée sur notre serveur en France.
          Elle produit des statistiques <strong>anonymes</strong> (pages vues, provenance des visites) pour
          améliorer la page, <strong>sans cookie</strong> et sans aucun suivi entre différents sites. L'adresse IP
          ne sert qu'à un décompte agrégé côté serveur, sans être conservée de façon identifiante ; aucune donnée
          n'est transmise à des tiers. Base légale : intérêt légitime. L'application elle-même n'est pas concernée
          par cette mesure.
        </p>
      </Section>

      <p className="pt-4 text-sm">
        <Link className="text-accent hover:underline" to="/">← Retour à l'application</Link>
      </p>
    </div>
  );
}
