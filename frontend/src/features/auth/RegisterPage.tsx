import { Check } from "lucide-react";
import { type FormEvent, useEffect, useState } from "react";
import { Link, useNavigate } from "react-router";

import { errorMessage } from "@/shared/lib/errorMessage";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { NewPasswordFields } from "@/shared/components/ui/new-password-fields";
import { Spinner } from "@/shared/components/ui/spinner";
import { cn } from "@/shared/lib/utils";
import { isPasswordValid } from "@/shared/lib/passwordPolicy";

import { AuthLayout } from "./AuthLayout";
import { useDevDemoRegister, useRegister, useRegisterClubLookup, useRegisterConfig } from "./queries";
import { TurnstileWidget } from "./TurnstileWidget";

// Miroir du serveur (AuthController: filter_var FILTER_VALIDATE_EMAIL) pour un
// retour immédiat au blur ; le serveur reste l'autorité.
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// P4-298 — MIROIR DÉCLARÉ (régime (2) de .claude/rules/frontend.md : duplication
// assumée pour la réactivité sans aller-retour réseau) du format FFBB serveur
// (FfbbApiClient::CLUB_CODE_RE, `^[A-Z]{2,4}\d{7}$`). Sert UNIQUEMENT à décider QUAND
// déclencher le lookup d'affichage — jamais à décider une règle métier (le serveur
// re-valide via isValidClubCode et reste l'autorité).
const FFBB_CODE_RE = /^[A-Z]{2,4}\d{7}$/;

/** Sports proposés. Basket seul aujourd'hui — les autres sont ANNONCÉS, désactivés.
 *  Le sport n'est pas encore un vrai choix (basket est posé côté serveur, cf.
 *  Club.sportId au seed) : l'écran affiche le sport du club, il ne toggle pas.
 *  Quand un 2e sport sera threadé (payload → token → createClub), rendre la
 *  sélection interactive ici. */
const SPORTS: { id: string; label: string; icon: string; enabled: boolean }[] = [
  { id: "basketball", label: "Basketball", icon: "🏀", enabled: true },
  { id: "handball", label: "Handball", icon: "🤾", enabled: false },
  { id: "volleyball", label: "Volleyball", icon: "🏐", enabled: false },
];

export function RegisterPage() {
  const register = useRegister();
  const navigate = useNavigate();
  // P2-4 — le raccourci démo (tenté après le 202 quand le serveur l'annonce).
  const demoRegister = useDevDemoRegister();
  // P5-3b — sitekey Turnstile (serveur). Absente (config null OU fetch en échec)
  // → Turnstile inactif : l'écran reste strictement l'actuel, sans widget tiers.
  const { data: registerCfg } = useRegisterConfig();
  const turnstileSiteKey = registerCfg?.turnstileSiteKey ?? null;
  // P2-4 — vrai en démo (kernel.debug serveur). Absent/false → écran actuel intact.
  const demoShortcut = registerCfg?.demoShortcut ?? false;
  // P2-4 (revue sécu) — l'adresse démo (serveur, en debug seulement). Le raccourci
  // n'est tenté QUE si l'adresse saisie est CELLE-LÀ : le mot de passe d'un vrai
  // prospect ne part jamais vers la route dev. Nulle en prod → jamais de tentative.
  const demoEmail = registerCfg?.demoEmail ?? null;
  // Étape 1 = choix du sport (basket présélectionné), étape 2 = les champs du sport.
  // Le sport n'est PAS envoyé au serveur : le seul choix est basket, que createClub
  // pose côté serveur. Au 2e sport, le threader (payload → token → createClub).
  const [step, setStep] = useState<"sport" | "details">("sport");
  const [form, setForm] = useState({ firstName: "", lastName: "", email: "", password: "", confirm: "", ara: "", club_name: "" });
  const [consent, setConsent] = useState(false);
  const [emailError, setEmailError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);
  // P2-4 — après un raccourci démo réussi (2xx), on ne connecte plus : on montre un écran
  // de démonstration (« ce clic fait les deux »), puis on invite à se connecter normalement.
  // Deux étapes : "explain" (l'explication + Continuer) → "ready" (Se connecter → /login).
  const [demoPhase, setDemoPhase] = useState<"explain" | "ready" | null>(null);
  // P5-3b — token Turnstile courant + un compteur qui, incrémenté, réarme le widget
  // (le token est à usage unique : après un refus serveur, il faut en redemander un).
  const [turnstileToken, setTurnstileToken] = useState<string | null>(null);
  const [turnstileReset, setTurnstileReset] = useState(0);

  // P4-298 — le code FFBB normalisé + sa version DEBOUNCÉE (≈400 ms). On ne déclenche le
  // lookup d'affichage qu'au format COMPLET valide (miroir FFBB_CODE_RE) : une saisie
  // partielle ne part JAMAIS au serveur (muet → rien), et on laisse la frappe se poser.
  const araUpper = form.ara.trim().toUpperCase();
  const [debouncedAra, setDebouncedAra] = useState("");
  useEffect(() => {
    const valid = FFBB_CODE_RE.test(araUpper);
    // setState UNIQUEMENT dans le callback différé (jamais synchrone dans l'effet) :
    // format complet → debounce ≈400 ms ; sinon → on vide (0 ms) pour couper le lookup.
    const timer = setTimeout(() => setDebouncedAra(valid ? araUpper : ""), valid ? 400 : 0);
    return () => clearTimeout(timer);
  }, [araUpper]);
  // AFFICHAGE SEUL : on lit `data` pour afficher, jamais pour pré-remplir `club_name`.
  const clubLookup = useRegisterClubLookup(debouncedAra, FFBB_CODE_RE.test(debouncedAra));

  const set = (key: keyof typeof form) => (event: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: event.target.value }));

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    if ("" === form.email || !EMAIL_RE.test(form.email)) {
      setError("Renseignez une adresse email valide.");
      return;
    }
    if (!isPasswordValid(form.password)) {
      setError("Le mot de passe ne respecte pas les critères.");
      return;
    }
    // La non-correspondance est déjà signalée sous le champ (NewPasswordFields) —
    // on bloque sans dupliquer le message au niveau du formulaire.
    if (form.password !== form.confirm) {
      return;
    }
    // noValidate rend le `required` de la case inerte : garde explicite avec
    // un message clair plutôt qu'un bouton désarmé silencieux (revue PR-5).
    if (!consent) {
      setError("Vous devez accepter les conditions d'utilisation et la politique de confidentialité.");
      return;
    }
    try {
      await register.mutateAsync({
        firstName: form.firstName,
        lastName: form.lastName,
        email: form.email,
        password: form.password,
        ara: form.ara.toUpperCase(),
        club_name: form.club_name,
        consent,
        // P5-3b — threadé seulement s'il existe (Turnstile inactif → aucun champ).
        ...(null !== turnstileToken ? { turnstileToken } : {}),
      });
      // P2-4 — en démo, le raccourci matérialise le club (is_demo peuplé). 2xx → on ne
      // connecte PLUS : on affiche l'écran de démonstration (« ce clic fait les deux »),
      // puis on invite à se connecter normalement. 409 → bannière explicite, SELON la
      // cause serveur. Tout autre échec (422 adresse non-démo, 404 hors debug, panne) →
      // fallback SILENCIEUX vers l'écran « vérifiez votre e-mail » : le rail register reste vrai.
      // ⚠ Le raccourci n'est tenté QUE si l'adresse saisie EST l'adresse démo — sinon le
      // mot de passe d'un vrai prospect serait posté une 2e fois vers une route dev.
      if (demoShortcut && null !== demoEmail && form.email.trim().toLowerCase() === demoEmail.toLowerCase()) {
        try {
          await demoRegister.mutateAsync({
            email: form.email,
            password: form.password,
            ara: form.ara.toUpperCase(),
            clubName: form.club_name,
          });
          setDemoPhase("explain");
          return;
        } catch (demoErr) {
          const err = demoErr as { response?: { status?: number }; data?: { error?: unknown } };
          const status = err.response?.status;
          // Conséquence de la vérification du mot de passe (le raccourci ne l'écrase
          // plus) : une faute de frappe → 401. On le NOMME et on reste sur le
          // formulaire (le fondateur corrige et retape) — surtout pas d'éjection vers
          // /login (le client ky exempte cette route, cf. shared/api/client.ts).
          if (401 === status) {
            setError("Mot de passe incorrect pour le compte de démonstration.");
            return;
          }
          if (409 === status) {
            // Le serveur est l'autorité sur la CAUSE (error code) — on ne fait que
            // choisir le libellé (présentation), sans re-dériver la règle métier.
            setError(err.data?.error === "teardown_refused"
              ? "Impossible de remplacer votre club de démonstration précédent. Réessayez, ou contactez le support."
              : "Ce code FFBB est déjà utilisé par un autre club : nous ne le remplaçons pas.");
            return;
          }
          // 422/404/autre : on retombe sur l'écran d'e-mail, sans rien dire.
        }
      }
      setSent(true);
    } catch (err) {
      setError(await errorMessage(err));
      // Token à usage unique : un refus (dont le 403 anti-robot) le consomme —
      // on l'oublie et on réarme le widget pour la tentative suivante.
      if (null !== turnstileSiteKey) {
        setTurnstileToken(null);
        setTurnstileReset((nonce) => nonce + 1);
      }
    }
  }

  // P2-4 — écrans de démonstration (après le raccourci réussi). Aucun e-mail n'est
  // envoyé sur ce chemin : le raccourci a déjà fait « les deux » côté serveur.
  if (null !== demoPhase) {
    return "explain" === demoPhase ? (
      <AuthLayout title="Démonstration">
        <div className="flex flex-col gap-4">
          <p className="text-sm text-foreground">
            Dans la vraie vie, vous confirmez votre e-mail et la boîte officielle de votre club valide votre inscription. Ce clic fait les deux.
          </p>
          <Button onClick={() => setDemoPhase("ready")}>Continuer</Button>
        </div>
      </AuthLayout>
    ) : (
      <AuthLayout title="Votre club est prêt">
        <div className="flex flex-col gap-4">
          <p className="text-sm text-foreground">Connectez-vous normalement.</p>
          <Button onClick={() => navigate("/login")}>Se connecter</Button>
        </div>
      </AuthLayout>
    );
  }

  if (sent) {
    return (
      <AuthLayout title="Créer un compte" description="Vérifiez votre boîte mail pour activer votre compte.">
        <p className="text-sm text-muted-foreground">
          Un email de confirmation vient d'être envoyé à <span className="text-foreground">{form.email}</span>. Ouvrez le lien qu'il contient pour activer votre compte. Pensez à vérifier vos spams.
        </p>
      </AuthLayout>
    );
  }

  if ("sport" === step) {
    return (
      <AuthLayout
        title="Votre sport"
        description="Choisissez le sport de votre club. D'autres arrivent bientôt."
        footer={<>Déjà un compte ? <Link className="text-accent hover:underline" to="/login">Se connecter</Link></>}
      >
        <div className="flex flex-col gap-4">
          <div className="grid grid-cols-3 gap-3">
            {SPORTS.map((s) => (
              // Affichage (pas un toggle) : basket est sélectionné, les autres sont
              // annoncés. Boutons désactivés — aucun n'est cliquable tant que basket
              // est le seul sport câblé de bout en bout.
              <button
                key={s.id}
                type="button"
                disabled
                aria-pressed={s.enabled}
                className={cn(
                  "relative flex flex-col items-center gap-2 rounded-lg border p-4 text-sm",
                  s.enabled ? "border-accent bg-accent/10 font-medium" : "cursor-not-allowed border-border opacity-40",
                )}
              >
                {s.enabled ? <Check aria-hidden className="absolute right-1.5 top-1.5 size-3.5 text-accent" /> : null}
                <span aria-hidden className="text-2xl leading-none">{s.icon}</span>
                <span>{s.label}</span>
                {s.enabled ? null : <span className="text-xs text-muted-foreground">bientôt</span>}
              </button>
            ))}
          </div>
          <Button onClick={() => setStep("details")}>Continuer</Button>
        </div>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout
      title="Créer un compte"
      description="Le code club FFBB identifie votre club. S'il existe déjà, votre demande sera soumise à l'approbation du gestionnaire."
      footer={<>Déjà un compte ? <Link className="text-accent hover:underline" to="/login">Se connecter</Link></>}
    >
      <form className="flex flex-col gap-4" onSubmit={onSubmit} noValidate>
        <div className="grid grid-cols-2 gap-3">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="firstName">Prénom</Label>
            <Input id="firstName" required value={form.firstName} onChange={set("firstName")} />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="lastName">Nom</Label>
            <Input id="lastName" required value={form.lastName} onChange={set("lastName")} />
          </div>
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="email">Email</Label>
          <Input
            id="email"
            type="email"
            autoComplete="email"
            required
            value={form.email}
            onChange={(e) => { set("email")(e); if (null !== emailError) setEmailError(null); }}
            onBlur={() => setEmailError("" !== form.email && !EMAIL_RE.test(form.email) ? "Adresse email invalide." : null)}
            aria-invalid={null !== emailError}
          />
          {emailError ? <p className="text-xs text-destructive">{emailError}</p> : null}
        </div>
        <NewPasswordFields
          password={form.password}
          confirm={form.confirm}
          onPasswordChange={(v) => setForm((prev) => ({ ...prev, password: v }))}
          onConfirmChange={(v) => setForm((prev) => ({ ...prev, confirm: v }))}
        />
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="ara">Code club FFBB</Label>
          <Input id="ara" required value={form.ara} onChange={set("ara")} placeholder="Ex. ARA0069013" className="uppercase" />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="club_name">Nom du club <span className="text-muted-foreground">(si nouveau club)</span></Label>
          <Input id="club_name" value={form.club_name} onChange={set("club_name")} />
          <p className="text-xs text-muted-foreground">Récupéré automatiquement depuis la FFBB si le code est reconnu.</p>
          {/* P4-298 — affichage SEUL (jamais de pré-remplissage de club_name). FFBB muette ou
              code encore partiel → rien (non bloquant). */}
          {clubLookup.data?.status === "found" ? (
            <p className="text-xs text-foreground">
              {clubLookup.data.city ? `${clubLookup.data.name} (${clubLookup.data.city})` : clubLookup.data.name} — c'est bien votre club ?
            </p>
          ) : clubLookup.data?.status === "unknown" ? (
            <p className="text-xs text-warning">Code non reconnu à la FFBB — vérifiez la saisie.</p>
          ) : null}
        </div>
        {/* RGPD : consentement explicite requis (le backend le refuse sans). */}
        <label className="flex items-start gap-2 text-sm">
          <input
            type="checkbox"
            className="mt-0.5 size-4 accent-[var(--accent)]"
            checked={consent}
            onChange={(e) => setConsent(e.target.checked)}
            required
          />
          <span>
            J'accepte les{" "}
            <Link className="text-accent hover:underline" to="/confidentialite" target="_blank" rel="noreferrer">
              conditions d'utilisation et la politique de confidentialité
            </Link>
            .
          </span>
        </label>
        {/* P5-3b — widget Turnstile : rendu UNIQUEMENT quand le serveur fournit une
            sitekey. Absente → rien ici, aucun script tiers, écran inchangé. */}
        {null !== turnstileSiteKey ? (
          <TurnstileWidget
            siteKey={turnstileSiteKey}
            onVerify={setTurnstileToken}
            onExpire={() => setTurnstileToken(null)}
            resetNonce={turnstileReset}
          />
        ) : null}
        {error ? <p className="text-sm text-destructive">{error}</p> : null}
        <div className="flex gap-2">
          <Button type="button" variant="outline" onClick={() => setStep("sport")}>
            Précédent
          </Button>
          {/* Désarmé tant que le mdp n'est pas valide OU non confirmé (comme reset
              /profil) : sinon un clic avec confirmation vide ne fait RIEN et lit
              comme une page cassée (revue #261 round 1). */}
          <Button type="submit" className="flex-1" disabled={register.isPending || !isPasswordValid(form.password) || form.password !== form.confirm}>
            {register.isPending ? <Spinner className="size-4" /> : null}
            Créer le compte
          </Button>
        </div>
      </form>
    </AuthLayout>
  );
}
