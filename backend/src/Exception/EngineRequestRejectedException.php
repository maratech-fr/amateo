<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Le moteur a REFUSÉ la requête de génération au niveau du CONTRAT (HTTP 422 Pydantic : un champ du
 * payload hors contrat, p.ex. `intensity:"OFF"` alors que le moteur n'accepte que HARD/PREFERRED).
 * DISTINCT d'un timeout (le moteur travaille trop lentement) et d'un `status:"failed"` renvoyé dans
 * le corps (le planning est infaisable) : ici le moteur n'a même pas commencé à chercher, il a
 * rejeté l'entrée.
 *
 * `EngineClient::solve()` la lève en traduisant le 422 ; `GenerateScheduleHandler` la rattrape AVANT
 * le `Throwable` générique pour poser un échec terminal LISIBLE (`engine_rejected`) nommant le(s)
 * champ(s) en cause — sans faire fuir à l'écran la valeur d'input refusée, le message anglais de
 * Pydantic ni l'URL interne du moteur.
 *
 * Elle ne porte QUE les CHEMINS de champ (`loc` Pydantic débarrassé de `body`, dédupliqués, bornés) :
 * ni la valeur refusée, ni le `msg` anglais — ce qui fuirait n'entre jamais dans l'exception.
 */
final class EngineRequestRejectedException extends RuntimeException
{
    /** Nombre maximum de champs retenus : au-delà, un message à rallonge n'aide plus. */
    private const int MAX_FIELDS = 5;

    /** @var list<string> */
    private array $fields;

    /** @param list<string> $fields */
    public function __construct(array $fields)
    {
        $this->fields = $fields;
        parent::__construct('Engine rejected the generation request (fields: ' . implode(', ', $fields) . ').');
    }

    /**
     * Construit l'exception depuis le CORPS d'erreur Pydantic décodé (`{detail: [{loc, msg, …}]}`).
     * Chaque `loc` devient un chemin pointé, `body` ôté (segment d'enveloppe FastAPI, aucune valeur
     * pour le gestionnaire) ; les chemins sont dédupliqués et bornés.
     *
     * @param array<string, mixed> $detail
     */
    public static function fromPydanticDetail(array $detail): self
    {
        $errors = $detail['detail'] ?? null;
        $fields = [];
        if (\is_array($errors)) {
            foreach ($errors as $error) {
                if (!\is_array($error) || !isset($error['loc']) || !\is_array($error['loc'])) {
                    continue;
                }
                $parts = [];
                foreach ($error['loc'] as $segment) {
                    if ('body' === $segment) {
                        continue;
                    }
                    if (\is_string($segment) || \is_int($segment)) {
                        $parts[] = (string) $segment;
                    }
                }
                if ([] !== $parts) {
                    $fields[] = implode('.', $parts);
                }
            }
        }

        $fields = \array_slice(array_values(array_unique($fields)), 0, self::MAX_FIELDS);

        return new self($fields);
    }

    /** @return list<string> */
    public function getFields(): array
    {
        return $this->fields;
    }
}
