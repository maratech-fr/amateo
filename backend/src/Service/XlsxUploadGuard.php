<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * La MAISON UNIQUE des bornes d'upload xlsx, partagée par les deux surfaces
 * (import de rencontres via {@see FixtureImportGate}, import d'équipes via
 * ImportController). Elle refuse TOUJOURS avant PhpSpreadsheet, pour que chaque
 * refus porte un message lisible plutôt qu'un 422 générique.
 *
 * Trois bornes APPLICATIVES, plus étroites que l'échelle infra (PHP 4M upload /
 * 8M post, nginx 20m) :
 *   - 2 Mo d'octets uploadés ;
 *   - 20 Mo cumulés une fois DÉCOMPRESSÉ (anti « zip bomb ») ;
 *   - 5 000 lignes.
 *
 * La borne ne fait confiance à AUCUN en-tête : elle ouvre le zip, streame
 * l'inflate de chaque entrée avec un compteur borné, et compte les balises
 * `<row` dans le flux des feuilles — jamais les tailles déclarées ni le
 * `<dimension ref>` (qui peut mentir). Un fichier qui n'est pas un zip lisible
 * n'est PAS refusé ici : il n'y a aucun inflate à borner, on le laisse au
 * parseur en aval dont le filet générique (P4-5) ne fuit rien.
 */
final class XlsxUploadGuard
{
    /** 2 Mo — la borne octets, mesurée AVANT tout parsing. */
    private const int MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

    /** 20 Mo cumulés une fois décompressé — bien en deçà d'un OOM, au-dessus de tout export réel (×7,3 mesuré). */
    private const int MAX_INFLATED_BYTES = 20 * 1024 * 1024;

    /** 5 000 lignes — un export FBI/équipes réel en compte quelques centaines. */
    private const int MAX_ROWS = 5000;

    /**
     * Recouvrement entre deux morceaux du flux : la plus longue correspondance de
     * {@see self::ROW_PATTERN} fait 5 octets (`<row` + un délimiteur), donc garder
     * 4 octets suffit à ne jamais couper une balise `<row` à la frontière — et pas
     * assez pour recompter une balise déjà comptée.
     */
    private const int ROW_OVERLAP = 4;

    /**
     * `<row` suivi d'un délimiteur ` `, `>`, `/` ou d'un blanc : compte les VRAIES
     * balises de ligne sans mordre sur `<rowBreaks` (autre élément du sheetML).
     */
    private const string ROW_PATTERN = '/<row[ \t\r\n>\/]/';

    /**
     * @param positive-int $chunkSize taille de lecture du flux inflaté ; réduite
     *                                dans le test unitaire pour prouver le comptage
     *                                à la frontière de deux morceaux (autowiring :
     *                                valeur par défaut)
     */
    public function __construct(
        private readonly int $chunkSize = 65536,
    ) {}

    /**
     * Le fichier uploadé s'il franchit les trois bornes, sinon la JsonResponse à
     * relayer telle quelle. Ordre des refus (les gardes d'auth/limiteur sont en
     * amont, dans l'appelant) : absent (400/413) → upload PHP invalide (413/400)
     * → octets > 2 Mo (413) → mime/extension (400) → décompressé/lignes (413).
     */
    public function requireXlsxFile(Request $request): UploadedFile|JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            // Cas > post_max_size (8M) : PHP a vidé $_FILES, on ne reçoit rien.
            // Si l'en-tête Content-Length dépasse la borne applicative, dire la
            // vérité (413 « 2 Mo ») plutôt que le 400 mensonger « aucun fichier ».
            // Repli sur le 400 honnête quand l'en-tête manque.
            $contentLength = $request->headers->get('Content-Length');
            if (null !== $contentLength && ctype_digit($contentLength) && (int) $contentLength > self::MAX_UPLOAD_BYTES) {
                return $this->tooLarge($this->bytesMessage());
            }

            return new JsonResponse(['error' => 'Aucun fichier n\'a été envoyé.'], Response::HTTP_BAD_REQUEST);
        }

        // Erreurs d'upload PHP : une taille dépassée (INI/FORM) est un 413 nommant
        // la borne ; tout autre code = le fichier n'a pas été reçu en entier (400
        // honnête, pas un mensonge « format invalide » sur des octets tronqués).
        if (!$file->isValid()) {
            if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
                return $this->tooLarge($this->bytesMessage());
            }

            return new JsonResponse(['error' => 'Le fichier n\'a pas été reçu en entier. Réessayez.'], Response::HTTP_BAD_REQUEST);
        }

        // Octets uploadés : la borne tombe AVANT tout parsing (un fichier de 2,1 Mo
        // ne sera jamais ouvert).
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->tooLarge($this->bytesMessage());
        }

        if ('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' !== $file->getMimeType()
            && !str_ends_with(strtolower($file->getClientOriginalName()), '.xlsx')
        ) {
            return new JsonResponse(['error' => 'Format de fichier invalide — seuls les fichiers .xlsx sont acceptés.'], Response::HTTP_BAD_REQUEST);
        }

        $refusal = $this->inspectArchive((string) $file->getRealPath());
        if ($refusal instanceof JsonResponse) {
            return $refusal;
        }

        return $file;
    }

    /**
     * Ouvre le zip et mesure en INFLATANT : octets décompressés cumulés (toutes
     * entrées) bornés à 20 Mo, balises `<row` des feuilles bornées à 5 000. On ne
     * lit jamais les tailles déclarées ni `<dimension ref>`. Un zip illisible
     * n'est pas notre affaire — retour null, le parseur en aval s'en charge.
     */
    private function inspectArchive(string $path): ?JsonResponse
    {
        $zip = new ZipArchive;
        if (true !== $zip->open($path)) {
            return null;
        }

        try {
            $inflated = 0;
            $rows = 0;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (false === $name) {
                    continue;
                }
                $stream = $zip->getStream($name);
                if (false === $stream) {
                    continue;
                }
                $isSheet = str_starts_with($name, 'xl/worksheets/') && str_ends_with($name, '.xml');
                $carry = '';
                try {
                    while (!feof($stream)) {
                        $chunk = fread($stream, $this->chunkSize);
                        if (false === $chunk || '' === $chunk) {
                            break;
                        }
                        $inflated += \strlen($chunk);
                        if ($inflated > self::MAX_INFLATED_BYTES) {
                            return $this->tooLarge($this->inflatedMessage());
                        }
                        if ($isSheet) {
                            $window = $carry . $chunk;
                            $rows += preg_match_all(self::ROW_PATTERN, $window);
                            if ($rows > self::MAX_ROWS) {
                                return $this->tooLarge($this->rowsMessage());
                            }
                            $carry = substr($window, -self::ROW_OVERLAP);
                        }
                    }
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            $zip->close();
        }

        return null;
    }

    private function tooLarge(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }

    private function bytesMessage(): string
    {
        return \sprintf(
            'Le fichier dépasse la taille maximale autorisée (%d Mo). Vous pouvez, si besoin, le découper par équipe ou par date avant de le réimporter.',
            intdiv(self::MAX_UPLOAD_BYTES, 1024 * 1024),
        );
    }

    private function inflatedMessage(): string
    {
        return \sprintf(
            'Une fois décompressé, le fichier dépasse la taille maximale autorisée (%d Mo). Vous pouvez, si besoin, le découper par équipe ou par date avant de le réimporter.',
            intdiv(self::MAX_INFLATED_BYTES, 1024 * 1024),
        );
    }

    private function rowsMessage(): string
    {
        return \sprintf(
            'Le fichier dépasse le nombre maximal de lignes autorisé (%s). Vous pouvez, si besoin, le découper par équipe ou par date avant de le réimporter.',
            number_format(self::MAX_ROWS, 0, ',', ' '),
        );
    }
}
