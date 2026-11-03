import { useMutation, useQuery } from "@tanstack/react-query";
import { HTTPError } from "ky";
import { BadgeCheck, ShieldQuestion, ShieldX } from "lucide-react";
import { useParams } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { Spinner } from "@/shared/components/ui/spinner";
import { PRODUCT_NAME } from "@/shared/lib/product";

import { AuthLayout } from "./AuthLayout";
import { decideClubApproval, getClubApproval, type ClubApprovalInfo } from "./api";
import { AUTH_TEXTS } from "./texts";

type Kind = NonNullable<ClubApprovalInfo["kind"]>;

/** Ce que la page dit d'une demande, selon sa nature (servie par le serveur, jamais redérivée). */
const KIND_COPY: Record<Kind, { title: string; description: string; body: string; bodyEnd: string; help: string }> = {
  create: {
    title: AUTH_TEXTS.clubApprovalCreateTitle,
    description: AUTH_TEXTS.clubApprovalCreateDescription(PRODUCT_NAME),
    body: AUTH_TEXTS.clubApprovalCreateBody,
    bodyEnd: ".",
    help: AUTH_TEXTS.clubApprovalCreateHelp,
  },
  reprise: {
    title: AUTH_TEXTS.clubApprovalRepriseTitle,
    description: AUTH_TEXTS.clubApprovalRepriseDescription(PRODUCT_NAME),
    body: AUTH_TEXTS.clubApprovalRepriseBody,
    bodyEnd: AUTH_TEXTS.clubApprovalRepriseBodyEnd,
    help: AUTH_TEXTS.clubApprovalRepriseHelp,
  },
  promotion: {
    title: AUTH_TEXTS.clubApprovalPromotionTitle,
    description: AUTH_TEXTS.clubApprovalPromotionDescription(PRODUCT_NAME),
    body: AUTH_TEXTS.clubApprovalPromotionBody,
    bodyEnd: AUTH_TEXTS.clubApprovalPromotionBodyEnd,
    help: AUTH_TEXTS.clubApprovalPromotionHelp,
  },
};

/** L'issue affichée après décision : celle que le serveur rend, dans les mots de la nature de la demande. */
function outcomeCopy(status: string, kind: Kind): { title: string; description: string; approved: boolean } {
  if ("approved" === status) {
    if ("reprise" === kind) {
      return { title: AUTH_TEXTS.clubApprovalRepriseApprovedTitle, description: AUTH_TEXTS.clubApprovalRepriseApprovedBody, approved: true };
    }
    if ("promotion" === kind) {
      return { title: AUTH_TEXTS.clubApprovalPromotionApprovedTitle, description: AUTH_TEXTS.clubApprovalPromotionApprovedBody, approved: true };
    }
    return { title: AUTH_TEXTS.clubApprovalCreatedTitle, description: AUTH_TEXTS.clubApprovalCreatedBody, approved: true };
  }
  if ("superseded" === status) {
    return {
      title: AUTH_TEXTS.clubApprovalSupersededTitle,
      description: "create" === kind ? AUTH_TEXTS.clubApprovalSupersededCreate : AUTH_TEXTS.clubApprovalSupersededTakenOver,
      approved: false,
    };
  }
  return { title: AUTH_TEXTS.clubApprovalRefusedTitle, description: AUTH_TEXTS.clubApprovalRefusedBody, approved: false };
}

/**
 * P3-4 PR C — la page PUBLIQUE d'approbation, ouverte par le destinataire du
 * mail officiel FFBB du club (pas de compte : le token EST l'identité). Elle
 * nomme le demandeur et le club, et porte les deux gestes — Approuver, Refuser.
 * 404 → lien invalide (ou déjà traité) ; 410 → expiré (le support reste la voie
 * de secours).
 *
 * Chantier Membre (PR 3) : la demande peut être une création, une REPRISE (club
 * sans gestionnaire) ou une PROMOTION (gestionnaires sans réponse) — `kind` servi
 * par le serveur ; l'adresse du demandeur est montrée pour une reprise ou une
 * promotion ; l'issue affichée est celle que le serveur rend (une demande dépassée
 * n'est jamais « refusée »).
 */
export function ClubApprovalPage() {
  const { token = "" } = useParams();

  const info = useQuery({
    queryKey: ["club-approval", token],
    queryFn: () => getClubApproval(token),
    retry: false,
  });

  const decide = useMutation({
    mutationFn: (decision: "approve" | "refuse") => decideClubApproval(token, decision),
  });

  const httpStatus = info.error instanceof HTTPError ? info.error.response.status : null;
  const kind: Kind = info.data?.kind ?? "create";
  const copy = KIND_COPY[kind];

  if (decide.isSuccess) {
    const outcome = outcomeCopy(decide.data.status, kind);
    return (
      <AuthLayout title={outcome.title} description={outcome.description}>
        <div className="flex flex-col items-center gap-4 py-2 text-center">
          <span className="flex size-12 items-center justify-center rounded-full bg-muted">
            {outcome.approved ? <BadgeCheck className="size-6 text-success" /> : <ShieldX className="size-6 text-accent" />}
          </span>
          <p className="text-sm text-muted-foreground">{AUTH_TEXTS.clubApprovalClosePage}</p>
        </div>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout title={copy.title} description={copy.description}>
      {info.isPending ? (
        <p className="flex items-center justify-center gap-2 py-4 text-sm text-muted-foreground">
          <Spinner className="size-4" /> {AUTH_TEXTS.clubApprovalLoading}
        </p>
      ) : info.isError ? (
        <div className="flex flex-col items-center gap-4 py-2 text-center">
          <span className="flex size-12 items-center justify-center rounded-full bg-muted">
            <ShieldQuestion className="size-6 text-accent" />
          </span>
          <p className="text-sm text-muted-foreground">
            {410 === httpStatus ? AUTH_TEXTS.clubApprovalExpired(PRODUCT_NAME) : AUTH_TEXTS.clubApprovalInvalid}
          </p>
        </div>
      ) : (
        <div className="flex flex-col gap-5">
          <div className="rounded-md border border-border bg-muted/40 px-4 py-3 text-sm">
            <p>
              <span className="font-medium">{info.data.requesterName}</span>
              {info.data.requesterEmail ? ` (${info.data.requesterEmail})` : null} {copy.body}{" "}
              <span className="font-medium">{info.data.clubName}</span> ({info.data.ara}){copy.bodyEnd}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">{AUTH_TEXTS.clubApprovalExpiresOn(info.data.expiresAt)}</p>
          </div>
          <p className="text-sm text-muted-foreground">{copy.help}</p>
          {decide.isError ? <p className="text-sm text-destructive">{AUTH_TEXTS.clubApprovalDecisionError}</p> : null}
          <div className="flex gap-3">
            <Button type="button" className="flex-1" disabled={decide.isPending} onClick={() => decide.mutate("approve")}>
              {decide.isPending ? <Spinner className="size-4" /> : null} {AUTH_TEXTS.clubApprovalApprove}
            </Button>
            <Button type="button" variant="outline" className="flex-1" disabled={decide.isPending} onClick={() => decide.mutate("refuse")}>
              {AUTH_TEXTS.clubApprovalRefuse}
            </Button>
          </div>
        </div>
      )}
    </AuthLayout>
  );
}
