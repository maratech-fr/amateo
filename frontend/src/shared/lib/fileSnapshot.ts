/**
 * Le `File` d'un `<input type="file">` n'est qu'un HANDLE : son contenu est RELU sur le
 * disque à CHAQUE envoi multipart. Si l'export est rouvert/ré-enregistré dans un autre
 * logiciel (Excel) entre l'analyse et l'envoi, Chrome rejette le second fetch
 * (`ERR_UPLOAD_FILE_CHANGED` → `TypeError`), que l'app traduit à tort en « Problème de
 * connexion » (aucune requête n'atteint le serveur — « Problème de connexion » chez le
 * fondateur, mémoire de session). On fige donc un SNAPSHOT mémoire à la SÉLECTION et on
 * l'envoie aux appels suivants — même nom, même type, donc multipart et nom de champ
 * inchangés côté serveur.
 *
 * Maison UNIQUE du snapshot (P3-7) : le dialogue d'import d'équipes ET `ImportFbiDialog`
 * le consomment. Rejette (throw) si la lecture disque échoue — l'appelant décide du
 * message affiché.
 */
export async function snapshotFile(file: File): Promise<File> {
  const buffer = await file.arrayBuffer();
  return new File([buffer], file.name, { type: file.type });
}
