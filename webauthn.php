<?php
declare(strict_types=1);

/**
 * A small WebAuthn verifier — just enough of the spec to bind writes to one
 * iPhone, with no Composer dependency.
 *
 * The shape of the thing: the phone holds a private key inside the Secure
 * Enclave that cannot be exported, even by Apple. The server holds only the
 * matching public key. To change a card's owned flag the phone must sign a
 * fresh random challenge, which takes a Face ID prompt. A stolen database, a
 * stolen cookie, or a copied URL gets an attacker nothing, because none of
 * them can produce a signature.
 *
 * Deliberately narrow:
 *   - Attestation is not verified. It answers "is this a genuine YubiKey /
 *     Apple device", which matters for enterprise policy and not at all for a
 *     personal page — the enrolment key already decides who may register.
 *   - ES256 (Apple, Android, most keys) and RS256 (Windows Hello) only.
 */

// ---- byte helpers ---------------------------------------------------

function b64url_encode(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function b64url_decode(string $s): string {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $out = base64_decode($s, true);
    return $out === false ? '' : $out;
}

// ---- CBOR -----------------------------------------------------------

/**
 * Decodes the CBOR subset WebAuthn actually emits: ints, byte and text
 * strings, arrays and maps. $offset advances past whatever was read, which is
 * how the caller finds where the public key ends inside authData.
 */
function cbor_decode(string $data, int &$offset = 0) {
    if ($offset >= strlen($data)) {
        throw new RuntimeException('CBOR: truncated input.');
    }
    $ib    = ord($data[$offset++]);
    $major = $ib >> 5;
    $arg   = $ib & 0x1f;

    if ($arg < 24) {
        $val = $arg;
    } elseif ($arg === 24) {
        $val = ord($data[$offset++]);
    } elseif ($arg === 25) {
        $val = unpack('n', substr($data, $offset, 2))[1]; $offset += 2;
    } elseif ($arg === 26) {
        $val = unpack('N', substr($data, $offset, 4))[1]; $offset += 4;
    } elseif ($arg === 27) {
        $val = unpack('J', substr($data, $offset, 8))[1]; $offset += 8;
    } else {
        throw new RuntimeException('CBOR: unsupported length encoding.');
    }

    switch ($major) {
        case 0: return $val;            // unsigned
        case 1: return -1 - $val;       // negative
        case 2:                         // byte string
        case 3:                         // text string
            $s = substr($data, $offset, $val);
            $offset += $val;
            return $s;
        case 4:                         // array
            $out = [];
            for ($i = 0; $i < $val; $i++) $out[] = cbor_decode($data, $offset);
            return $out;
        case 5:                         // map
            $out = [];
            for ($i = 0; $i < $val; $i++) {
                $k = cbor_decode($data, $offset);
                $out[is_int($k) ? $k : (string)$k] = cbor_decode($data, $offset);
            }
            return $out;
        case 6:                         // tag — skip it, decode the content
            return cbor_decode($data, $offset);
        case 7:
            if ($val === 20) return false;
            if ($val === 21) return true;
            if ($val === 22) return null;
            return $val;
        default:
            throw new RuntimeException('CBOR: unknown major type.');
    }
}

// ---- DER / COSE -----------------------------------------------------

function der_len(int $n): string {
    if ($n < 0x80) return chr($n);
    $b = '';
    while ($n > 0) { $b = chr($n & 0xff) . $b; $n >>= 8; }
    return chr(0x80 | strlen($b)) . $b;
}

function der_tlv(int $tag, string $body): string {
    return chr($tag) . der_len(strlen($body)) . $body;
}

/** DER INTEGER — strips leading zeros, then re-pads if the high bit is set. */
function der_uint(string $bytes): string {
    $bytes = ltrim($bytes, "\x00");
    if ($bytes === '') $bytes = "\x00";
    if (ord($bytes[0]) & 0x80) $bytes = "\x00" . $bytes;
    return der_tlv(0x02, $bytes);
}

function pem_wrap(string $der): string {
    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

/**
 * Turns a COSE_Key from the authenticator into a PEM public key openssl can
 * use. Returns [pem, alg] where alg is the COSE identifier.
 */
function cose_to_pem(array $cose): array {
    $kty = $cose[1] ?? null;
    $alg = $cose[3] ?? null;

    if ($kty === 2) {                                  // EC2
        if ($alg !== -7) throw new RuntimeException('Unsupported EC algorithm.');
        if (($cose[-1] ?? null) !== 1) throw new RuntimeException('Expected the P-256 curve.');
        $x = $cose[-2] ?? '';
        $y = $cose[-3] ?? '';
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            throw new RuntimeException('Malformed EC public key.');
        }
        $algId = der_tlv(0x30,
              der_tlv(0x06, hex2bin('2a8648ce3d0201'))   // ecPublicKey
            . der_tlv(0x06, hex2bin('2a8648ce3d030107'))); // prime256v1
        $point = der_tlv(0x03, "\x00" . "\x04" . $x . $y);
        return [pem_wrap(der_tlv(0x30, $algId . $point)), -7];
    }

    if ($kty === 3) {                                  // RSA
        if ($alg !== -257) throw new RuntimeException('Unsupported RSA algorithm.');
        $n = $cose[-1] ?? '';
        $e = $cose[-2] ?? '';
        if ($n === '' || $e === '') throw new RuntimeException('Malformed RSA public key.');
        $rsa   = der_tlv(0x30, der_uint($n) . der_uint($e));
        $algId = der_tlv(0x30, der_tlv(0x06, hex2bin('2a864886f70d010101')) . "\x05\x00");
        return [pem_wrap(der_tlv(0x30, $algId . der_tlv(0x03, "\x00" . $rsa))), -257];
    }

    throw new RuntimeException('Unsupported key type.');
}

// ---- authData -------------------------------------------------------

/** Splits the authenticator data structure into its fields. */
function parse_auth_data(string $ad): array {
    if (strlen($ad) < 37) throw new RuntimeException('authData too short.');
    $out = [
        'rpIdHash'  => substr($ad, 0, 32),
        'flags'     => ord($ad[32]),
        'signCount' => unpack('N', substr($ad, 33, 4))[1],
        'credentialId' => null,
        'publicKey'    => null,
    ];
    // Bit 6 (0x40) — attested credential data is appended. Set on registration.
    if ($out['flags'] & 0x40) {
        if (strlen($ad) < 55) throw new RuntimeException('Attested credential data truncated.');
        $len = unpack('n', substr($ad, 53, 2))[1];         // after the 16-byte AAGUID
        $out['credentialId'] = substr($ad, 55, $len);
        $offset = 55 + $len;
        $out['publicKey'] = cbor_decode($ad, $offset);
    }
    return $out;
}

// ---- shared checks --------------------------------------------------

/**
 * The parts registration and assertion verify identically: the client data is
 * for the ceremony we started, carries the challenge we issued, and came from
 * our own origin.
 */
function check_client_data(string $json, string $expectedType, string $challenge, array $origins): array {
    $c = json_decode($json, true);
    if (!is_array($c)) throw new RuntimeException('clientDataJSON is not valid JSON.');

    if (($c['type'] ?? '') !== $expectedType) {
        throw new RuntimeException('Wrong ceremony type.');
    }
    // hash_equals: the comparison is against a value the caller controls.
    if (!hash_equals($challenge, (string)($c['challenge'] ?? ''))) {
        throw new RuntimeException('Challenge did not match.');
    }
    if (!in_array((string)($c['origin'] ?? ''), $origins, true)) {
        throw new RuntimeException('Origin not allowed: ' . (string)($c['origin'] ?? ''));
    }
    return $c;
}

function check_rp_id(string $rpIdHash, string $rpId): void {
    if (!hash_equals(hash('sha256', $rpId, true), $rpIdHash)) {
        throw new RuntimeException('Relying party ID did not match.');
    }
}

// ---- ceremonies -----------------------------------------------------

/**
 * Verifies a registration and returns the row to store.
 * $challenge is the base64url challenge this server issued.
 */
function webauthn_verify_registration(
    string $clientDataJSON,
    string $attestationObject,
    string $challenge,
    string $rpId,
    array  $origins
): array {
    check_client_data($clientDataJSON, 'webauthn.create', $challenge, $origins);

    $offset = 0;
    $att    = cbor_decode($attestationObject, $offset);
    if (!is_array($att) || !isset($att['authData'])) {
        throw new RuntimeException('Malformed attestation object.');
    }

    $ad = parse_auth_data($att['authData']);
    check_rp_id($ad['rpIdHash'], $rpId);

    // Bit 0 — user present. The Face ID prompt is what sets it.
    if (!($ad['flags'] & 0x01)) throw new RuntimeException('User presence flag not set.');
    if (!$ad['credentialId'])   throw new RuntimeException('No credential in attestation.');

    [$pem, $alg] = cose_to_pem($ad['publicKey']);

    return [
        'credential_id' => $ad['credentialId'],
        'public_key'    => $pem,
        'alg'           => $alg,
        'sign_count'    => $ad['signCount'],
    ];
}

/**
 * Verifies an assertion against a stored credential. Returns the new signature
 * counter. Throws on any failure — callers should treat a throw as "denied".
 */
function webauthn_verify_assertion(
    string $clientDataJSON,
    string $authenticatorData,
    string $signature,
    string $challenge,
    string $publicKeyPem,
    int    $storedSignCount,
    string $rpId,
    array  $origins
): int {
    check_client_data($clientDataJSON, 'webauthn.get', $challenge, $origins);

    $ad = parse_auth_data($authenticatorData);
    check_rp_id($ad['rpIdHash'], $rpId);
    if (!($ad['flags'] & 0x01)) throw new RuntimeException('User presence flag not set.');

    // The authenticator signs authData concatenated with the client data hash.
    $signed = $authenticatorData . hash('sha256', $clientDataJSON, true);

    $key = openssl_pkey_get_public($publicKeyPem);
    if ($key === false) throw new RuntimeException('Stored public key is unreadable.');

    $ok = openssl_verify($signed, $signature, $key, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) throw new RuntimeException('Signature did not verify.');

    // A counter that goes backwards means two authenticators share one key,
    // i.e. a clone. Authenticators that never count (Apple passkeys) sit at 0
    // forever, so only a nonzero counter is worth policing.
    if ($ad['signCount'] > 0 && $storedSignCount > 0 && $ad['signCount'] <= $storedSignCount) {
        throw new RuntimeException('Signature counter did not advance — possible cloned key.');
    }

    return $ad['signCount'];
}
