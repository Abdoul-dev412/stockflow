<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

final class CsvExport
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /** Empêche l'exécution d'une formule à l'ouverture dans un tableur. Les nombres restent intacts. */
    public static function neutralize(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], self::DANGEROUS_PREFIXES, true) ? "'".$value : $value;
    }

    /** Montant avec deux décimales et virgule, pour un tableur en français. */
    public static function decimal(string|float|int|null $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, ',', '');
    }

    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public static function download(string $name, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 : accents corrects dans Excel
            self::write($out, $headers);

            foreach ($rows as $row) {
                self::write($out, $row);
            }

            fclose($out);
        }, $name.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function write($stream, array $row): void
    {
        fputcsv($stream, array_map([self::class, 'neutralize'], $row), ';', '"', '');
    }
}