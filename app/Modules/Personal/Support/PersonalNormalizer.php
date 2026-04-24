<?php

namespace App\Modules\Personal\Support;

use DateTime;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;

class PersonalNormalizer
{
    public static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    public static function normalizeKey(string $value): string
    {
        $plain = mb_strtolower(self::text($value));
        $plain = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $plain) ?: $plain;

        return preg_replace('/[^a-z0-9]+/', '', $plain) ?: '';
    }

    public static function dni(mixed $value): string
    {
        $raw = self::text($value);

        if ($raw === '') {
            return '';
        }

        if (preg_match('/^\d+(\.0+)?$/', $raw)) {
            $raw = str_replace('.0', '', $raw);
        }

        $dni = preg_replace('/[^\d]/', '', $raw) ?: '';

        if ($dni !== '' && strlen($dni) < 8) {
            $dni = str_pad($dni, 8, '0', STR_PAD_LEFT);
        }

        return $dni;
    }

    public static function isValidDni(string $dni): bool
    {
        return preg_match('/^\d{8}$/', $dni) === 1;
    }

    public static function contract(mixed $value): string
    {
        $contract = self::normalizeKey(self::text($value));

        if ($contract === '') {
            return 'REG';
        }

        $aliases = [
            'regimen' => 'REG',
            'reg' => 'REG',
            'se' => 'FIJO',
            'fijo' => 'FIJO',
            'intermitente' => 'INTER',
            'inter' => 'INTER',
            'indeterminado' => 'INDET',
            'indet' => 'INDET',
        ];

        return $aliases[$contract] ?? 'REG';
    }

    public static function contractLabel(?string $contract): string
    {
        $normalized = self::contract($contract);

        return match ($normalized) {
            'REG' => 'Regimen',
            'FIJO' => 'Fijo',
            'INTER' => 'Intermitente',
            'INDET' => 'Indeterminado',
            default => 'Regimen',
        };
    }

    public static function mineStatus(mixed $value): ?string
    {
        $text = strtoupper(self::text($value));

        if ($text === '') {
            return null;
        }

        if (str_contains($text, 'NO HABILITADO')) {
            return 'NO_HABILITADO';
        }

        if (str_contains($text, 'EN PROCESO')) {
            return 'EN_PROCESO';
        }

        if (str_contains($text, 'HABILITADO')) {
            return 'HABILITADO';
        }

        return null;
    }

    public static function mineStatusFromInput(mixed $value): string
    {
        $text = strtoupper(self::text($value));

        return match ($text) {
            'HABILITADO' => 'HABILITADO',
            'EN_PROCESO', 'PROCESO' => 'EN_PROCESO',
            'NO_HABILITADO' => 'NO_HABILITADO',
            default => 'HABILITADO',
        };
    }

    public static function mineStatusLabel(?string $status): string
    {
        $normalized = strtoupper(self::text($status));

        return match ($normalized) {
            'HABILITADO' => 'habilitado',
            'EN_PROCESO' => 'proceso',
            'NO_HABILITADO' => 'no_habilitado',
            default => 'proceso',
        };
    }

    public static function isSupervisorOccupation(mixed $value): bool
    {
        $occupation = strtoupper(self::text($value));

        return in_array($occupation, ['E', 'P'], true);
    }

    public static function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value)) {
            try {
                return SpreadsheetDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $text = self::text($value);
        if ($text === '') {
            return null;
        }

        try {
            return (new DateTime($text))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function normalizePhonePayload(mixed $value): array
    {
        $raw = self::text($value);
        if ($raw === '') {
            return [
                'telefono_1' => null,
                'telefono_2' => null,
                'valid_count' => 0,
                'raw_has_content' => false,
                'had_invalid_cleanup' => false,
                'had_more_than_two' => false,
                'had_duplicates' => false,
            ];
        }

        $compact = preg_replace('/[\t\r\n]+/', ' ', $raw) ?? $raw;
        $compact = preg_replace('/\s+/', ' ', trim($compact)) ?? trim($compact);

        preg_match_all('/\+?\d[\d\s]{5,}\d/', $compact, $matches);
        $tokens = $matches[0] ?? [];

        if (count($tokens) === 0) {
            preg_match_all('/\d+/', $compact, $fallbackMatches);
            $tokens = $fallbackMatches[0] ?? [];
        }

        $allCandidates = [];
        foreach ($tokens as $token) {
            $digits = preg_replace('/\D+/', '', (string) $token) ?: '';
            if ($digits === '') {
                continue;
            }

            if (strlen($digits) < 6) {
                continue;
            }

            $allCandidates[] = $digits;
        }

        $uniqueCandidates = array_values(array_unique($allCandidates));
        $selected = array_slice($uniqueCandidates, 0, 2);

        $telefono1 = $selected[0] ?? null;
        $telefono2 = $selected[1] ?? null;

        $nonDigitNoise = preg_replace('/[\d\s\/\-\|,;\.\+]/', '', $compact) ?? '';
        $hadInvalidCleanup = trim($nonDigitNoise) !== '' || count($tokens) > count($allCandidates);

        return [
            'telefono_1' => $telefono1,
            'telefono_2' => $telefono2,
            'valid_count' => count($selected),
            'raw_has_content' => $compact !== '',
            'had_invalid_cleanup' => $hadInvalidCleanup,
            'had_more_than_two' => count($uniqueCandidates) > 2,
            'had_duplicates' => count($allCandidates) > count($uniqueCandidates),
            'all_valid_numbers' => $uniqueCandidates,
            'raw' => $compact,
        ];
    }

    public static function combinePhones(?string $telefono1, ?string $telefono2): ?string
    {
        $first = self::text($telefono1);
        $second = self::text($telefono2);

        if ($first === '' && $second === '') {
            return null;
        }

        if ($first !== '' && $second !== '' && $first !== $second) {
            return $first . ' / ' . $second;
        }

        return $first !== '' ? $first : $second;
    }
}
