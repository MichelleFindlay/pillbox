<?php
declare(strict_types=1);

/*
 * Medicine lookup in the NHS Dictionary of Medicines and Devices (dm+d), via the
 * NHS England Terminology Server (FHIR R4). dm+d content is licensed, so the server
 * needs a (free) system-to-system account: client credentials.
 */

// dm+d virtual medicinal product (VMP) simple reference set: generic products such as
// "Metformin 500mg tablets", which is how most prescriptions are written.
const DMD_VMP_REFSET = '999000561000001109';

function dmd_enabled(): bool
{
    return config('nhs_terminology.enabled') && config('nhs_terminology.client_id') !== ''
        && config('nhs_terminology.client_secret') !== '';
}

function nhs_http(string $url, ?array $form = null, ?string $token = null): array
{
    $headers = ['Accept: application/fhir+json, application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($body === false) {
        throw new RuntimeException('Could not contact the NHS Terminology Server: ' . curl_error($ch));
    }
    $data = json_decode((string) $body, true);
    if ($status === 401) {
        throw new NhsAuthException('The NHS Terminology Server rejected the credentials.');
    }
    if (!is_array($data) || $status >= 400) {
        error_log("NHS Terminology Server HTTP $status: " . substr((string) $body, 0, 1000));
        throw new RuntimeException("The NHS Terminology Server returned an error (HTTP $status).");
    }
    return $data;
}

final class NhsAuthException extends RuntimeException
{
}

function nhs_token_cache_file(): string
{
    return storage_path('nhs_terminology_token_' . md5((string) config('nhs_terminology.client_id')) . '.json');
}

/** Client-credentials access token, cached until shortly before it expires. */
function nhs_terminology_token(bool $refresh = false): string
{
    $cache = nhs_token_cache_file();
    if (!$refresh && is_file($cache)) {
        // Kept encrypted (with storage/app.key), like the other tokens Pillbox stores.
        $cached = json_decode((string) unseal((string) file_get_contents($cache)), true);
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 30) {
            return (string) $cached['access_token'];
        }
    }
    $data = nhs_http((string) config('nhs_terminology.token_url'), [
        'grant_type'    => 'client_credentials',
        'client_id'     => config('nhs_terminology.client_id'),
        'client_secret' => config('nhs_terminology.client_secret'),
    ]);
    if (empty($data['access_token'])) {
        throw new RuntimeException('The NHS Terminology Server did not return an access token.');
    }
    $expiresAt = time() + (int) ($data['expires_in'] ?? 300);
    if (@file_put_contents($cache, seal((string) json_encode(['access_token' => $data['access_token'], 'expires_at' => $expiresAt])), LOCK_EX)) {
        @chmod($cache, 0600);
    }
    return (string) $data['access_token'];
}

/**
 * Search dm+d for medicines matching $term.
 * @return list<array{code: string, display: string, name: string, strength: string, form: string, dose_unit: string}>
 */
function dmd_search(string $term): array
{
    $url = rtrim((string) config('nhs_terminology.fhir_base'), '/') . '/ValueSet/$expand?' . http_build_query([
        'url'    => 'http://snomed.info/sct?fhir_vs=ecl/' . config('nhs_terminology.ecl', '^' . DMD_VMP_REFSET),
        'filter' => $term,
        'count'  => 20,
    ]);

    try {
        $data = nhs_http($url, null, nhs_terminology_token());
    } catch (NhsAuthException) {
        // The cached token may have been revoked early: get a fresh one and retry once.
        $data = nhs_http($url, null, nhs_terminology_token(true));
    }

    $results = [];
    foreach ($data['expansion']['contains'] ?? [] as $concept) {
        if (!isset($concept['code'], $concept['display'])) {
            continue;
        }
        $results[] = ['code' => (string) $concept['code'], 'display' => (string) $concept['display']] + dmd_parse((string) $concept['display']);
    }
    return $results;
}

/**
 * Split a dm+d product name into Pillbox fields, e.g.
 *   "Metformin 500mg tablets"                  -> Metformin / 500mg / tablet / tablet
 *   "Salbutamol 100micrograms/dose inhaler"    -> Salbutamol / 100micrograms/dose / inhaler / puff
 *   "Amoxicillin 250mg/5ml oral suspension"    -> Amoxicillin / 250mg/5ml / liquid / ml
 */
function dmd_parse(string $display): array
{
    $unit = '(?:mg|micrograms?|nanograms?|g|grams?|ml|litres?|mmol|units?|million units|%)';
    $denominator = '(?:\s*/\s*[\d.,]*\s*(?:mg|g|ml|dose|actuation|spray|drop|hours?|24hours|litres?))?';
    $pattern = "~^(.+?)\s+(\d[\d.,]*\s*{$unit}{$denominator}(?:\s*/\s*\d[\d.,]*\s*{$unit}{$denominator})*)\s+(.+)$~i";

    if (preg_match($pattern, $display, $m)) {
        [$name, $strength, $rest] = [$m[1], $m[2], $m[3]];
    } else {
        [$name, $strength, $rest] = [$display, '', $display];
    }

    // First matching keyword wins, so more specific forms come first.
    $forms = [
        'inhal'         => ['inhaler', 'puff'],
        'injection'     => ['injection', 'injection'],
        'infusion'      => ['injection', 'injection'],
        'eye drop'      => ['drops', 'drop'],
        'ear drop'      => ['drops', 'drop'],
        'drops'         => ['drops', 'drop'],
        'patch'         => ['patch', 'patch'],
        'suppositor'    => ['suppository', 'suppository'],
        'spray'         => ['spray', 'spray'],
        'cream'         => ['cream', 'application'],
        'ointment'      => ['cream', 'application'],
        'gel'           => ['cream', 'application'],
        'capsule'       => ['capsule', 'capsule'],
        'tablet'        => ['tablet', 'tablet'],
        'oral solution' => ['liquid', 'ml'],
        'suspension'    => ['liquid', 'ml'],
        'liquid'        => ['liquid', 'ml'],
        'syrup'         => ['liquid', 'ml'],
        'elixir'        => ['liquid', 'ml'],
        'sachet'        => ['sachet', 'sachet'],
        'granules'      => ['sachet', 'sachet'],
        'powder'        => ['sachet', 'sachet'],
    ];
    [$form, $doseUnit] = ['other', 'unit'];
    foreach ($forms as $keyword => $mapped) {
        if (stripos($rest, $keyword) !== false) {
            [$form, $doseUnit] = $mapped;
            break;
        }
    }

    return [
        'name'      => mb_substr(trim($name), 0, 150),
        'strength'  => mb_substr(str_replace(' ', '', $strength), 0, 60),
        'form'      => $form,
        'dose_unit' => $doseUnit,
    ];
}
