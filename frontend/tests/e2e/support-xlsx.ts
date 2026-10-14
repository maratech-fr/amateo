/**
 * Fabrique un .xlsx MINIMAL à la volée, sans dépendance nouvelle (P3-7).
 *
 * ⚑ Pourquoi pas un fixture statique : l'import d'équipes refuse un fichier dont le code
 * club (colonne Organisme) ne correspond pas au club appelant, et le `ffbbClubCode` d'un
 * club fraîchement inscrit VAUT son ARA, unique par run e2e (`uniqueAra`). L'Organisme
 * doit donc être construit AVEC l'ARA du run — un .xlsx sur disque ne le peut pas.
 *
 * Un .xlsx est un ZIP de parties XML. On écrit le minimum qu'attend le lecteur Xlsx de
 * PhpSpreadsheet (côté serveur), avec des chaînes EN LIGNE (pas de sharedStrings), et on
 * empaquette en ZIP « STORED » (aucune compression) — crc32 implémenté ici, donc zéro
 * dépendance ajoutée.
 */

/** Une ligne d'équipe du fichier — colonnes attendues par `FfbbExcelImporter`. */
export interface TeamXlsxRow {
  nom: string;
  categorie: string;
  numero: string;
  /** Le libellé Organisme : le code AVANT « - » est le code club (doit égaler l'ARA). */
  organisme: string;
}

const CRC_TABLE: number[] = (() => {
  const table: number[] = [];
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) {
      c = 1 === (c & 1) ? 0xed_b8_83_20 ^ (c >>> 1) : c >>> 1;
    }
    table[n] = c >>> 0;
  }
  return table;
})();

function crc32(buf: Buffer): number {
  let crc = ~0;
  for (const byte of buf) {
    crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
  }
  return (~crc) >>> 0;
}

const escapeXml = (value: string): string =>
  value.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");

interface ZipEntry {
  name: string;
  data: Buffer;
}

/** Empaquette des parties en ZIP STORED (méthode 0), lisible par ZipArchive/PhpSpreadsheet. */
function zip(entries: ZipEntry[]): Buffer {
  const locals: Buffer[] = [];
  const central: Buffer[] = [];
  let offset = 0;

  for (const entry of entries) {
    const nameBuf = Buffer.from(entry.name, "utf8");
    const crc = crc32(entry.data);
    const size = entry.data.length;

    const localHeader = Buffer.alloc(30);
    localHeader.writeUInt32LE(0x04_03_4b_50, 0); // local file header signature
    localHeader.writeUInt16LE(20, 4); // version needed
    localHeader.writeUInt16LE(0, 6); // flags
    localHeader.writeUInt16LE(0, 8); // method: STORED
    localHeader.writeUInt16LE(0, 10); // mod time
    localHeader.writeUInt16LE(0, 12); // mod date
    localHeader.writeUInt32LE(crc, 14);
    localHeader.writeUInt32LE(size, 18); // compressed size
    localHeader.writeUInt32LE(size, 22); // uncompressed size
    localHeader.writeUInt16LE(nameBuf.length, 26);
    localHeader.writeUInt16LE(0, 28); // extra length
    locals.push(localHeader, nameBuf, entry.data);

    const centralHeader = Buffer.alloc(46);
    centralHeader.writeUInt32LE(0x02_01_4b_50, 0); // central dir signature
    centralHeader.writeUInt16LE(20, 4); // version made by
    centralHeader.writeUInt16LE(20, 6); // version needed
    centralHeader.writeUInt16LE(0, 8); // flags
    centralHeader.writeUInt16LE(0, 10); // method
    centralHeader.writeUInt16LE(0, 12); // time
    centralHeader.writeUInt16LE(0, 14); // date
    centralHeader.writeUInt32LE(crc, 16);
    centralHeader.writeUInt32LE(size, 20);
    centralHeader.writeUInt32LE(size, 24);
    centralHeader.writeUInt16LE(nameBuf.length, 28);
    centralHeader.writeUInt16LE(0, 30); // extra length
    centralHeader.writeUInt16LE(0, 32); // comment length
    centralHeader.writeUInt16LE(0, 34); // disk number
    centralHeader.writeUInt16LE(0, 36); // internal attrs
    centralHeader.writeUInt32LE(0, 38); // external attrs
    centralHeader.writeUInt32LE(offset, 42); // local header offset
    central.push(centralHeader, nameBuf);

    offset += 30 + nameBuf.length + size;
  }

  const centralBuf = Buffer.concat(central);
  const eocd = Buffer.alloc(22);
  eocd.writeUInt32LE(0x06_05_4b_50, 0); // end of central dir signature
  eocd.writeUInt16LE(0, 4); // disk number
  eocd.writeUInt16LE(0, 6); // central dir start disk
  eocd.writeUInt16LE(entries.length, 8);
  eocd.writeUInt16LE(entries.length, 10);
  eocd.writeUInt32LE(centralBuf.length, 12);
  eocd.writeUInt32LE(offset, 16); // central dir offset
  eocd.writeUInt16LE(0, 20); // comment length

  return Buffer.concat([...locals, centralBuf, eocd]);
}

/** Lettre de colonne A1 (A, B, …, Z, AA…) pour l'index 0-based — assez pour nos 4 à 7 colonnes. */
function colLetter(index: number): string {
  let i = index;
  let out = "";
  do {
    out = String.fromCharCode(65 + (i % 26)) + out;
    i = Math.floor(i / 26) - 1;
  } while (i >= 0);
  return out;
}

/** Une `<row>` de chaînes en ligne, réf. cellules {Col}{n} — nombre de colonnes libre. */
function sheetRow(index: number, values: string[]): string {
  const cells = values
    .map((value, i) => `<c r="${colLetter(i)}${index}" t="inlineStr"><is><t xml:space="preserve">${escapeXml(value)}</t></is></c>`)
    .join("");
  return `<row r="${index}">${cells}</row>`;
}

/**
 * Empaquette UN onglet (en-tête + lignes) en .xlsx lisible par PhpSpreadsheet. Foyer commun des
 * deux constructeurs (équipes, rencontres FBI) — un seul endroit pour la plomberie OOXML/ZIP.
 */
function buildSingleSheetXlsx(sheetName: string, header: string[], rows: string[][]): Buffer {
  const headerRow = sheetRow(1, header);
  const body = rows.map((cells, i) => sheetRow(i + 2, cells)).join("");

  const sheet =
    `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` +
    `<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">` +
    `<sheetData>${headerRow}${body}</sheetData></worksheet>`;

  const contentTypes =
    `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` +
    `<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">` +
    `<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>` +
    `<Default Extension="xml" ContentType="application/xml"/>` +
    `<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>` +
    `<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>` +
    `</Types>`;

  const rootRels =
    `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` +
    `<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">` +
    `<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>` +
    `</Relationships>`;

  const workbook =
    `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` +
    `<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">` +
    `<sheets><sheet name="${escapeXml(sheetName)}" sheetId="1" r:id="rId1"/></sheets></workbook>`;

  const workbookRels =
    `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>` +
    `<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">` +
    `<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>` +
    `</Relationships>`;

  return zip([
    { name: "[Content_Types].xml", data: Buffer.from(contentTypes, "utf8") },
    { name: "_rels/.rels", data: Buffer.from(rootRels, "utf8") },
    { name: "xl/workbook.xml", data: Buffer.from(workbook, "utf8") },
    { name: "xl/_rels/workbook.xml.rels", data: Buffer.from(workbookRels, "utf8") },
    { name: "xl/worksheets/sheet1.xml", data: Buffer.from(sheet, "utf8") },
  ]);
}

/**
 * Construit le buffer .xlsx d'un export FBI d'ÉQUIPES : en-tête « Nom | Catégorie | Numéro |
 * Organisme » puis une ligne par équipe. À passer à `locator.setInputFiles({ name, mimeType, buffer })`.
 */
export function buildTeamsXlsx(rows: TeamXlsxRow[]): Buffer {
  return buildSingleSheetXlsx(
    "Equipes",
    ["Nom", "Catégorie", "Numéro", "Organisme"],
    rows.map((r) => [r.nom, r.categorie, r.numero, r.organisme]),
  );
}

// ── Export FBI des RENCONTRES (distinct de l'export des ÉQUIPES ci-dessus) ────────────────────

/**
 * Une ligne de rencontre du fichier FBI — colonnes attendues par `FbiFixtureImporter`
 * (ordre : Division · N° de match · Equipe 1 (domicile) · Equipe 2 (extérieur) · Date · Heure · Salle).
 *
 * ⚠ Le DOMICILE/EXTÉRIEUR se déduit du NOM DU CLUB (pas d'un code organisme, contrairement à
 * l'import d'équipes) : le nom du club doit apparaître dans EXACTEMENT UN des deux côtés
 * (`FbiFixtureImporter::containsClub`). `equipe1` = côté domicile, `equipe2` = côté extérieur.
 */
export interface FixtureXlsxRow {
  division: string;
  numero: string;
  equipe1: string;
  equipe2: string;
  /** Format jj/mm/aaaa (ou vide). */
  date: string;
  /** Format HH:MM ; « 00:00 » = sentinelle FBI « heure non posée ». Optionnel. */
  heure?: string;
  /** Libellé de salle FFBB. Optionnel. */
  salle?: string;
}

/**
 * Construit le buffer .xlsx d'un export FBI de RENCONTRES. En-tête EXACT de l'export réel —
 * « N° de match » porte une espace finale, reproduite ici (cf. `FbiFixtureImporter`).
 */
export function buildFixturesXlsx(rows: FixtureXlsxRow[]): Buffer {
  return buildSingleSheetXlsx(
    "Rencontres",
    ["Division", "N° de match ", "Equipe 1", "Equipe 2", "Date de rencontre", "Heure", "Salle"],
    rows.map((r) => [r.division, r.numero, r.equipe1, r.equipe2, r.date, r.heure ?? "", r.salle ?? ""]),
  );
}

/** Une équipe du club et le libellé de division sous lequel ses rencontres arrivent dans le fichier. */
export interface CanonicalTeamFixtures {
  /** Le nom d'équipe (= libellé de division FBI dans le fichier, pour un mappage division→équipe 1-pour-1). */
  division: string;
  /** Nombre de journées (rencontres) de l'équipe. */
  journees: number;
}

/**
 * Le décor FBI CANONIQUE (charte des tests, §4) : un club de 10 équipes, import =
 * 6 équipes × 5 journées + 3 × 10 + 1 × 22 ≈ 82 rencontres, domicile/extérieur alternés.
 *
 * `clubName` est posé dans le côté domicile ou extérieur selon la parité de la journée, de sorte
 * que `FbiFixtureImporter` reconnaisse le club et en déduise le sens. Les adversaires sont des
 * libellés distincts qui ne contiennent jamais le nom du club. Les dates sont des samedis
 * successifs à partir de `firstSaturday` (jj/mm/aaaa) ; l'heure est fixée à 20:00.
 */
export function buildCanonicalFbiFixtures(opts: {
  clubName: string;
  teams: CanonicalTeamFixtures[];
  /** Premier samedi (Date) d'où partent les journées. */
  firstSaturday: Date;
  venueLabel?: string;
}): FixtureXlsxRow[] {
  const { clubName, teams, firstSaturday, venueLabel = "Gymnase FBI e2e" } = opts;
  const fmt = (d: Date): string => {
    const dd = String(d.getUTCDate()).padStart(2, "0");
    const mm = String(d.getUTCMonth() + 1).padStart(2, "0");
    return `${dd}/${mm}/${d.getUTCFullYear()}`;
  };
  const rows: FixtureXlsxRow[] = [];
  let matchNo = 1;
  for (const team of teams) {
    for (let j = 0; j < team.journees; j += 1) {
      const date = new Date(firstSaturday.getTime());
      date.setUTCDate(firstSaturday.getUTCDate() + j * 7);
      const opponent = `ADVERSAIRE ${String((j % 20) + 1).padStart(2, "0")}`;
      const home = 0 === j % 2; // journées paires = domicile
      rows.push({
        division: team.division,
        numero: String(matchNo).padStart(5, "0"),
        equipe1: home ? clubName : opponent,
        equipe2: home ? opponent : clubName,
        date: fmt(date),
        heure: "20:00",
        salle: venueLabel,
      });
      matchNo += 1;
    }
  }
  return rows;
}
