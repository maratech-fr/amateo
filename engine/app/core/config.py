from functools import lru_cache

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=(".env",),
        env_file_encoding="utf-8",
        env_prefix="ENGINE_",
        extra="ignore",
    )

    app_name: str = Field(default="engine")
    app_version: str = Field(default="1.0")
    environment: str = Field(default="dev")
    log_level: str = Field(default="info")
    # Sentry error capture (ENGINE_SENTRY_DSN). Empty = SDK disabled (no-op init):
    # everything is wired now, activated the day the SaaS account exists.
    sentry_dsn: str = Field(default="")
    # Max solves running concurrently across all clubs. 1 preserves the current
    # de-facto serialisation (the event loop no longer blocks — see build_schedule
    # running _solve in a worker thread) while keeping CPU contention bounded.
    max_concurrent_solves: int = Field(default=1, ge=1)
    # AUD-ENG-30 — budget SÉPARÉ pour le rail matchs. `/place-matches` est SYNCHRONE
    # (ADR-0003 : pas de Messenger, pas de Mercure — le gestionnaire attend la réponse
    # HTTP) et dure ~3 s, quand `/generate` peut tenir 600 s. Les faire partager un
    # sémaphore de 1 revenait à faire attendre un appel synchrone derrière un solve
    # hebdomadaire : timeout côté gestionnaire, sur une opération de trois secondes.
    #
    # Le verrou de club de `/place-matches` était DÉJÀ préfixé pour éviter cela
    # (`matches:{club_id}`) — le sémaphore partagé défaisait cette intention.
    #
    # Coût assumé : au pire 1 génération + 1 placement en parallèle au lieu d'un solve
    # seul. Le placement est un petit problème daté, sans commune mesure avec le solve
    # hebdomadaire ; le CPU reste borné, il n'est plus borné à UN.
    max_concurrent_placements: int = Field(default=1, ge=1)
    # AUD-ENG-33 — budget PROPRE au rail verdict. Même classe d'incident qu'ENG-30 ci-dessus,
    # réintroduite par la porte du voisin : `/validate-assignments` partageait le sémaphore de
    # `/place-matches` alors que leurs budgets sont ASYMÉTRIQUES, et mesurés —
    #
    #   * placement : solveur 60 s (`MatchPlacementPayloadBuilder.php`), transport 90 s ;
    #   * verdict   : solveur 2 s, transport **20 s** (`MoveSlotService.php`), valeur calée sur
    #     mesure (« 9 à 9,6 s de calcul réel constatés sur le club réel »).
    #
    # Un placement du club A tenant l'unique jeton jusqu'à 60 s faisait donc échouer, par
    # famine, le verdict LÉGAL du club B — qui abandonne à 20 s. Un club en faisait tomber un
    # autre, et le gestionnaire lisait un message honnête sur une cause fausse.
    #
    # Un budget propre, PAS un budget plus large : élargir `max_concurrent_placements` aurait
    # aussi autorisé deux placements de 60 s en parallèle, sans garantir qu'un verdict ne se
    # retrouve pas derrière eux. Coût assumé : au pire 1 génération + 1 placement + 1 verdict,
    # et le verdict est le plus petit des trois (baseline figée, un seul candidat épinglé).
    #
    # ⚠ Résidu ASSUMÉ à 1 : deux verdicts de deux clubs se sérialisent encore. Sur la mesure
    # connue (~10 s), deux verdicts empilés frôlent les 20 s. On garde 1 — monter à 2 double le
    # CPU pour une classe d'incident jamais observée, quand celle qu'on corrige est réelle.
    max_concurrent_verdicts: int = Field(default=1, ge=1)


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()
