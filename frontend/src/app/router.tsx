import { createBrowserRouter, RouterProvider } from "react-router";

import { routes } from "@/app/routes";

/**
 * Construit à la PREMIÈRE utilisation, pas à l'import : `createBrowserRouter`
 * s'initialise tout seul (il lit l'historique et lance une navigation). Au
 * niveau module, il s'exécutait donc dans chaque test important `routes`.
 */
let router: ReturnType<typeof createBrowserRouter> | null = null;

export function AppRouter() {
  router ??= createBrowserRouter(routes);

  return <RouterProvider router={router} />;
}
