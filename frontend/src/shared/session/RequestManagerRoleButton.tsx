import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";

import { useRequestManagerRole } from "./queries";
import { SESSION_TEXTS } from "./texts";

/**
 * « Demander à devenir gestionnaire » — maison unique du geste, partagée par le bandeau de
 * fermeture (`ClubClosingBanner`) et la carte « Mon rôle » de `/club`. La demande ne part qu'après
 * confirmation (le dialogue dit où elle va) ; « Annuler » ou Échap ne l'envoient pas.
 */
export function RequestManagerRoleButton({ size = "sm" }: { size?: "sm" | "default" }) {
  const request = useRequestManagerRole();
  const [confirming, setConfirming] = useState(false);
  return (
    <>
      <Button type="button" size={size} disabled={request.isPending} onClick={() => setConfirming(true)}>
        {SESSION_TEXTS.requestManagerRole}
      </Button>
      <ConfirmDialog
        open={confirming}
        title={SESSION_TEXTS.requestManagerRoleConfirmTitle}
        description={SESSION_TEXTS.requestManagerRoleConfirmDescription}
        confirmLabel={SESSION_TEXTS.requestManagerRoleConfirmLabel}
        destructive={false}
        onCancel={() => setConfirming(false)}
        onConfirm={() => {
          setConfirming(false);
          request.mutate();
        }}
      />
    </>
  );
}
