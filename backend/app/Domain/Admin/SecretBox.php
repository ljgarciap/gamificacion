<?php

namespace App\Domain\Admin;

use App\Domain\Crypto\Secrets;

/**
 * Cifrado en reposo de secretos del panel (secreto TOTP, secretos de host): XChaCha20-Poly1305 con la clave GAMIF_ADMIN_KEY.
 * Igual que PrizeVault, cada texto cifrado queda LIGADO a su fila (`tabla|id|columna`): copiarlo a otro usuario o a otra columna falla al abrirlo.
 * Formato guardado: nonce (24 bytes) ‖ texto cifrado con etiqueta de autenticación.
 */
final class SecretBox
{
    private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    public function __construct(private readonly ?string $keyOverride = null) {}

    private function key(): string
    {
        return $this->keyOverride ?? Secrets::key('admin_key');
    }

    public function seal(string $plain, string $context): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);

        return $nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, 'gamif/v1/aead/admin|'.$context, $nonce, $this->key());
    }

    public function open(string $sealed, string $context): string
    {
        if (strlen($sealed) <= self::NONCE_BYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new \RuntimeException('texto cifrado demasiado corto');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($sealed, self::NONCE_BYTES), 'gamif/v1/aead/admin|'.$context, substr($sealed, 0, self::NONCE_BYTES), $this->key(),
        );
        if ($plain === false) {
            throw new \RuntimeException('no se pudo descifrar: clave incorrecta o dato alterado/movido');
        }

        return $plain;
    }
}
