import { useQuery } from "@tanstack/react-query";
import { useParams, useSearchParams } from "react-router";

import { AuthLayout } from "@/features/auth/AuthLayout";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Spinner } from "@/shared/components/ui/spinner";

import { getCampaignPreview } from "./campaignApi";
import { PublicWishForm } from "./PublicWishPage";

/**
 * Aperçu COACH PAR COACH d'une campagne de collecte (feature #10, lot D2) — page standalone
 * AUTHENTIFIÉE (`/doleances/apercu/:campaignId?coach=…`). Le gestionnaire voit la VRAIE page
 * telle que CE coach la verra (son prénom, ses équipes, les partenaires possibles), en LECTURE
 * SEULE : aucune mutation, aucun brouillon, envoi désactivé (bandeau « Aperçu »).
 *
 * Elle réutilise le formulaire public (`PublicWishForm` + prop `preview`) — un seul rendu pour
 * la page réelle et son aperçu, sans jeton (le GET authentifié `/preview` tient lieu d'identité).
 */
export function PreviewWishPage() {
  const { campaignId = "" } = useParams();
  const [search] = useSearchParams();
  const coachId = search.get("coach") ?? "";

  const query = useQuery({
    queryKey: ["coach-wish-preview", campaignId, coachId],
    queryFn: () => getCampaignPreview(campaignId, coachId),
    enabled: "" !== campaignId && "" !== coachId,
    retry: false,
    staleTime: 0,
  });

  if ("" === coachId) {
    return (
      <AuthLayout title="Aperçu" description="Choisissez un coach">
        <EmptyHint>Aucun coach sélectionné pour l'aperçu.</EmptyHint>
      </AuthLayout>
    );
  }

  if (query.isLoading) {
    return (
      <AuthLayout title="Aperçu" description="Chargement…">
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Spinner className="size-4" />
          Un instant…
        </p>
      </AuthLayout>
    );
  }

  if (query.isError || undefined === query.data) {
    return (
      <AuthLayout title="Aperçu indisponible" description="Impossible de charger l'aperçu.">
        <p className="text-sm text-muted-foreground">Ce coach n'est pas (ou plus) dans la collecte, ou la campagne est introuvable.</p>
      </AuthLayout>
    );
  }

  return <PublicWishForm token="" context={query.data} preview />;
}
