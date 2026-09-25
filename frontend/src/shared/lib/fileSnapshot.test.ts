import { describe, expect, it } from "vitest";

import { snapshotFile } from "./fileSnapshot";

describe("snapshotFile", () => {
  it("returns a NEW File carrying the same bytes, name and type", async () => {
    const original = new File(["hello xlsx bytes"], "equipes.xlsx", {
      type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    });

    const snapshot = await snapshotFile(original);

    // Un nouvel objet (le snapshot mémoire), pas le handle disque d'origine.
    expect(snapshot).not.toBe(original);
    expect(snapshot.name).toBe("equipes.xlsx");
    expect(snapshot.type).toBe("application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    // Le contenu est bien recopié : c'est ce qui immunise l'envoi contre un fichier
    // ré-écrit sur le disque entre l'analyse et l'import.
    expect(await snapshot.text()).toBe("hello xlsx bytes");
  });

  it("propagates a read failure so the caller can surface its own message", async () => {
    // Un handle dont la lecture disque échoue (ERR_UPLOAD_FILE_CHANGED simulé) : le
    // helper ne l'avale pas, il rejette — l'appelant traduit en message utilisateur.
    const broken = {
      name: "equipes.xlsx",
      type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      arrayBuffer: () => Promise.reject(new Error("ERR_UPLOAD_FILE_CHANGED")),
    } as unknown as File;

    await expect(snapshotFile(broken)).rejects.toThrow();
  });
});
