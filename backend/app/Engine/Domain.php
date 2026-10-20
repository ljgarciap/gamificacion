<?php

declare(strict_types=1);

namespace App\Engine;

/** Etiquetas de dominio: cada uso del hash lleva la suya, así un valor de un contexto nunca vale en otro. */
final class Domain
{
    public const POOL = 'gamif/v1/pool';

    public const CODES = 'gamif/v1/codes';

    public const COMMIT = 'gamif/v1/commit';

    public const MIX = 'gamif/v1/mix';

    public const SHUFFLE = 'gamif/v1/shuffle';
}
