<?php

declare(strict_types=1);

namespace Pediacare\Validation;

use DateTimeImmutable;
use InvalidArgumentException;

final class VisitValidator
{
    public const SUPPLEMENTS = ['VITAMIN_A' => 'Vitamin A', 'DEWORMING' => 'Deworming',
        'IRON' => 'Iron', 'MNP' => 'Micronutrient powder'];

    public function validate(array $input, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $data = ['visit_date' => $this->date($input['visit_date'] ?? null, 'Visit date', $today),
            'reason' => $this->text($input['reason'] ?? '', 'Reason or chief complaint', 255, true)];
        foreach (['findings', 'actions', 'notes'] as $field) {
            $data[$field] = $this->text($input[$field] ?? '', ucfirst($field), 65535, false, true);
        }
        if (!in_array($input['referred'] ?? null, ['0', '1'], true)) {
            throw new InvalidArgumentException('Choose whether the child was referred.');
        }
        $data['referred'] = (int) $input['referred'];
        $data['referred_to'] = $this->text($input['referred_to'] ?? '', 'Referral destination', 255, $data['referred'] === 1);
        if ($data['referred'] === 0 && $data['referred_to'] !== null) {
            throw new InvalidArgumentException('Choose Yes for referral or leave the referral destination blank.');
        }
        $rows = $input['supplements'] ?? [];
        if (!is_array($rows)) {
            throw new InvalidArgumentException('Enter valid supplement dose and date fields.');
        }
        $supplements = [];
        foreach ($rows as $type => $row) {
            if (!isset(self::SUPPLEMENTS[$type]) || !is_array($row)) {
                throw new InvalidArgumentException('Choose a supported preventive service.');
            }
            $dose = $this->text($row['dose'] ?? '', self::SUPPLEMENTS[$type] . ' dose', 100);
            $date = $row['date_given'] ?? '';
            if ($dose === null && $date === '') {
                continue;
            }
            if ($dose === null) {
                throw new InvalidArgumentException('Enter the dose for ' . self::SUPPLEMENTS[$type] . '.');
            }
            $supplements[] = ['type' => $type, 'dose' => $dose,
                'date_given' => $this->date($date, self::SUPPLEMENTS[$type] . ' date given', $today)];
        }

        return ['visit' => $data, 'supplements' => $supplements];
    }

    private function text(mixed $value, string $label, int $limit, bool $required = false, bool $bytes = false): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Enter valid text for ' . $label . '.');
        }
        $value = trim($value);
        if (($bytes ? strlen($value) : mb_strlen($value)) > $limit || ($required && $value === '')) {
            throw new InvalidArgumentException('Enter ' . $label . ' within the ' . $limit . ($bytes ? '-byte' : '-character') . ' limit.');
        }

        return $value === '' ? null : $value;
    }

    private function date(mixed $value, string $label, DateTimeImmutable $today): string
    {
        if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) !== 1) {
            throw new InvalidArgumentException('Enter a valid ' . $label . '.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value || $value < '1000-01-01' || $value > $today->format('Y-m-d')) {
            throw new InvalidArgumentException('Enter a valid ' . $label . ' on or before today.');
        }

        return $value;
    }
}
