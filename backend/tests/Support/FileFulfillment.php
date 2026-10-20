<?php

namespace Tests\Support;

use App\Domain\Fulfillment\PrizeFulfillment;

/** Entrega "al host" escribiendo en un archivo compartido (con candado): sirve para contar entregas de varios procesos. */
final class FileFulfillment implements PrizeFulfillment
{
    public function __construct(private readonly string $path) {}

    public function deliver(array $payload): void
    {
        $fh = fopen($this->path, 'ab');
        flock($fh, LOCK_EX);
        fwrite($fh, $payload['redemption_id']."\n");
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
