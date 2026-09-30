import { type FormEvent, useEffect, useRef, useState } from "react";
import { Link, useNavigate, useNavigation } from "react-router";

import { errorMessage } from "@/shared/lib/errorMessage";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { PasswordInput } from "@/shared/components/ui/password-input";
import { Spinner } from "@/shared/components/ui/spinner";
import { clearSessionExpired, peekSessionExpired } from "@/shared/lib/sessionExpiredNotice";
import { useLoginSplashStore } from "@/shared/stores/loginSplashStore";

import { AuthLayout } from "./AuthLayout";
import { useLogin } from "./queries";

export function LoginPage() {
  const navigate = useNavigate();
  // Le chunk du cockpit se télécharge après le login : la navigation n'est pas instantanée.
  const navigating = "idle" !== useNavigation().state;
  const login = useLogin();
  const startSplash = useLoginSplashStore((s) => s.start);
  const cancelSplash = useLoginSplashStore((s) => s.cancel);
  const emailRef = useRef<HTMLInputElement>(null);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  // P5-14 — la session a-t-elle expiré juste avant ce retour ? Marqueur one-shot posé
  // par client.ts sur un 401. On PEEK (lecture pure) dans l'initialiseur — sûr sous
  // StrictMode qui double-invoque — puis on CLEAR dans un effet (pas de setState dans
  // l'effet : la règle `react-hooks/set-state-in-effect` l'interdit, et c'est de
  // toute façon un simple nettoyage). Le bloc apparaît donc dès le premier rendu, une
  // seule fois : au second montage le marqueur a été retiré.
  const [sessionExpired] = useState(peekSessionExpired);
  useEffect(() => {
    clearSessionExpired();
  }, []);

  // P4-252 — identifiants refusés : le splash s'efface (`cancelSplash`, appelé au catch) et le
  // focus revient au champ e-mail (le vrai geste à reprendre). Clé sur `error` : `setError(null)`
  // au submit puis le message à l'échec = la valeur change à chaque tentative, l'effet se rejoue.
  // Il tourne APRÈS le commit qui a retiré l'`inert` (phase `cancelling`), donc le focus prend.
  useEffect(() => {
    if (null !== error) {
      emailRef.current?.focus();
    }
  }, [error]);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    // P4-252 — le splash « Signature » couvre l'écran DÈS le clic (avant même la réponse réseau).
    startSplash();
    try {
      await login.mutateAsync({ email, password });
      // AuthGuard routes to the app / waiting screen based on membership status. Le splash, monté
      // dans RootShell, survit à cette navigation et se termine quand l'app est prête.
      navigate("/", { replace: true });
    } catch (err) {
      cancelSplash();
      setError(await errorMessage(err));
    }
  }

  return (
    <AuthLayout
      title="Connexion"
      description="Accédez à l'espace de gestion de votre club."
      footer={
        <>
          Pas encore de compte ? <Link className="text-accent hover:underline" to="/register">Créer un compte</Link>
        </>
      }
    >
      {/* P5-14 — réassurance quand on ARRIVE ici parce que la session a expiré. Bloc
          `role="status"` poli (une réassurance ne doit pas interrompre une frappe en
          cours), titre en h2 (jamais un second h1 de la page), et « Se reconnecter »
          EST le formulaire déjà présent — pas de page séparée. Le focus va au champ
          e-mail (le vrai geste), pas au bloc. */}
      {sessionExpired ? (
        <div role="status" aria-live="polite" className="mb-4 rounded-md border border-border bg-card p-3 text-left">
          <h2 className="text-sm font-semibold text-foreground">Fin du temps réglementaire.</h2>
          <p className="mt-1 text-sm text-foreground">Votre session a expiré après une période d'inactivité — c'est pour protéger les données du club. Reconnectez-vous, vous reprendrez là où vous en étiez.</p>
        </div>
      ) : null}
      <form className="flex flex-col gap-4" onSubmit={onSubmit} noValidate>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="email">Email</Label>
          <Input ref={emailRef} id="email" type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
        </div>
        <div className="flex flex-col gap-1.5">
          <div className="flex items-center justify-between">
            <Label htmlFor="password">Mot de passe</Label>
            <Link className="text-xs text-muted-foreground hover:text-accent hover:underline" to="/forgot-password">
              Oublié ?
            </Link>
          </div>
          <PasswordInput id="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
        </div>
        {error ? <p className="text-sm text-destructive">{error}</p> : null}
        {/* P4-6 — `navigate("/")` ne commite plus dans le même rendu : le chunk du
            cockpit se télécharge d'abord. Sans `navigating`, le bouton se ré-activait
            sur un formulaire encore affiché → double POST /api/login → throttle SEC-11
            pour un utilisateur en fait DÉJÀ authentifié. */}
        <Button type="submit" disabled={login.isPending || navigating}>
          {login.isPending || navigating ? <Spinner className="size-4" /> : null}
          Se connecter
        </Button>
      </form>
    </AuthLayout>
  );
}
