<?php

namespace App\Domain\Codes;

/** Resultado de generar los códigos de un lote. Los códigos en claro NO están aquí: solo existen en el archivo exportado. */
final readonly class GeneratedCodes
{
    public function __construct(
        public string $batchId,
        public int $count,
        public string $path,
        public string $sha256,
        public string $prefix,
    ) {}
}
