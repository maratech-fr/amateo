// Chantier E — mémorisation PAR CLUB de « Afficher le nom des coachs » à l'export, dans le
// navigateur. Clé `export-show-coaches-<clubId>` (coordination page planning ⇄ cockpit).
// DÉCOCHÉ par défaut. Lecture/écriture gardées try/catch : un stockage inaccessible (navigation
// privée, site data bloqué) ne lève jamais — défaut sûr (false) en lecture, silence en écriture.

const keyFor = (clubId: string): string => `export-show-coaches-${clubId}`;

export function getShowCoaches(clubId: string): boolean {
  try {
    return "true" === localStorage.getItem(keyFor(clubId));
  } catch {
    return false;
  }
}

export function setShowCoaches(clubId: string, value: boolean): void {
  try {
    if (value) {
      localStorage.setItem(keyFor(clubId), "true");
    } else {
      localStorage.removeItem(keyFor(clubId));
    }
  } catch {
    // Stockage inaccessible : on ne mémorise pas, mais on ne casse pas la page.
  }
}

// « Afficher les occupations » : même mémorisation par club, clé `export-show-occupancies-<clubId>`,
// décochée par défaut, mêmes gardes.
const occupanciesKeyFor = (clubId: string): string => `export-show-occupancies-${clubId}`;

export function getShowOccupancies(clubId: string): boolean {
  try {
    return "true" === localStorage.getItem(occupanciesKeyFor(clubId));
  } catch {
    return false;
  }
}

export function setShowOccupancies(clubId: string, value: boolean): void {
  try {
    if (value) {
      localStorage.setItem(occupanciesKeyFor(clubId), "true");
    } else {
      localStorage.removeItem(occupanciesKeyFor(clubId));
    }
  } catch {
    // Stockage inaccessible : on ne mémorise pas, mais on ne casse pas la page.
  }
}
