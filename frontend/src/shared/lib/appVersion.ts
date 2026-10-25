/**
 * P4-355 — la version du BUNDLE front, foyer UNIQUE. Cuite au build par la variable
 * `VITE_APP_VERSION` (build-arg du `docker/frontend/Dockerfile`, posée au déploiement
 * avec le tag/sha — `.github/workflows/deploy.yml`). Hors d'un build taggé (dev, jsdom,
 * e2e), repli « dev ».
 *
 * Jointe au contexte de BASE d'un signalement (les deux portes) ; le support la compare
 * à la version de l'API résolue serveur (« App vX · API vY ») pour détecter un bundle
 * resté en cache après un déploiement.
 */
export const APP_VERSION: string = import.meta.env.VITE_APP_VERSION || "dev";
