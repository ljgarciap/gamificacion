<?php

namespace App\Domain\Batches;

/** El lote o su pool no cumple las reglas de negocio (pool inválido, premio inexistente, faltan códigos...). */
class InvalidBatch extends \DomainException {}
