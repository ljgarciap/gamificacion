<?php

namespace App\Domain\Crypto;

use App\Engine\Bytes;

/**
 * Cifrado en reposo del mapa código→premio y de la semilla (R3).
 *
 * XChaCha20-Poly1305 (libsodium): cifra y autentica. Cada texto cifrado queda LIGADO a su fila mediante datos asociados
 * (lote + índice): si alguien copia el premio cifrado de un código a otro, o lo mueve a otro lote, el descifrado falla.
 * Formato guardado: nonce (24 bytes) ‖ texto cifrado con etiqueta de autenticación.
 *
 * Límite honesto: quien tenga a la vez la base de datos y la clave puede leer los premios. Esto evita que lea el mapa
 * quien solo tiene la base (un volcado, un analista, un respaldo). La clave vive fuera de la base.
 */
final class PrizeVault
{
    private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES; // 24

    public function __construct(private readonly ?string $keyOverride = null) {}

    private function key(): string
    {
        return $this->keyOverride ?? Secrets::key('prize_map_key');
    }

    public function encryptPrize(string $batchId, int $idx, string $prizeId): string
    {
        return $this->seal($prizeId, $this->prizeAad($batchId, $idx));
    }

    public function decryptPrize(string $batchId, int $idx, string $sealed): string
    {
        return $this->open($sealed, $this->prizeAad($batchId, $idx));
    }

    /**
     * Premio de una casilla de reserva. Contexto de TIPO distinto al de los códigos y ligado a la posición GLOBAL del mazo (N + idx):
     * intercambiar el texto cifrado de un código con el de una casilla no pasa la autenticación.
     */
    public function encryptReservePrize(string $batchId, int $deckIdx, string $prizeId): string
    {
        return $this->seal($prizeId, $this->reserveAad($batchId, $deckIdx));
    }

    public function decryptReservePrize(string $batchId, int $deckIdx, string $sealed): string
    {
        return $this->open($sealed, $this->reserveAad($batchId, $deckIdx));
    }

    public function encryptSeed(string $batchId, string $seed): string
    {
        if (strlen($seed) !== 32) {
            throw new \InvalidArgumentException('la semilla debe tener 32 bytes');
        }

        return $this->seal($seed, $this->seedAad($batchId));
    }

    public function decryptSeed(string $batchId, string $sealed): string
    {
        return $this->open($sealed, $this->seedAad($batchId));
    }

    private function prizeAad(string $batchId, int $idx): string
    {
        return 'gamif/v1/aead/prize'.Bytes::lp($batchId).Bytes::be32($idx);
    }

    private function reserveAad(string $batchId, int $deckIdx): string
    {
        return 'gamif/v2/aead/reserve-prize'.Bytes::lp($batchId).Bytes::be32($deckIdx);
    }

    private function seedAad(string $batchId): string
    {
        return 'gamif/v1/aead/seed'.Bytes::lp($batchId);
    }

    private function seal(string $plain, string $aad): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);

        return $nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $aad, $nonce, $this->key());
    }

    private function open(string $sealed, string $aad): string
    {
        if (strlen($sealed) <= self::NONCE_BYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new \RuntimeException('texto cifrado demasiado corto');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($sealed, self::NONCE_BYTES), $aad, substr($sealed, 0, self::NONCE_BYTES), $this->key(),
        );
        if ($plain === false) {
            // No distingue "clave equivocada" de "fila equivocada" de "dato alterado": todos son integridad rota.
            throw new \RuntimeException('no se pudo descifrar: clave incorrecta o dato alterado/movido');
        }

        return $plain;
    }
}
