<?php

declare(strict_types=1);

namespace Pediacare\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use OutOfRangeException;
use PDO;
use Throwable;
use Pediacare\Domain\Enums\AuditAction;
use Pediacare\Domain\Enums\MeasurementPosition;
use Pediacare\Domain\Enums\Sex;
use Pediacare\Domain\Growth\AgeInMonths;
use Pediacare\Domain\Growth\Classifier;
use Pediacare\Domain\Growth\GrowthCalculator;
use Pediacare\Domain\Growth\Reference\Who2006Standard;
use Pediacare\Domain\Growth\Reference\Who2007Reference;
use Pediacare\Domain\MeasurementResult;
use Pediacare\Repositories\ChildRepository;
use Pediacare\Repositories\MeasurementRepository;
use Pediacare\Repositories\WhoLmsRepository;
use Pediacare\Support\AuditLogger;
use Pediacare\Support\MeasurementConfirmation;
use Pediacare\Validation\MeasurementValidator;

final class MeasurementService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ChildRepository $children,
        private readonly MeasurementRepository $measurements,
        private readonly WhoLmsRepository $references,
        private readonly MeasurementValidator $validator,
        private readonly AuditLogger $audit,
        private readonly MeasurementConfirmation $confirmation,
    ) {
    }

    public function record(string $childId, array $input, int $userId, ?DateTimeImmutable $today = null): MeasurementResult
    {
        if (filter_var($childId, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) === false) {
            throw new OutOfBoundsException('Child record not found.');
        }
        if ($userId < 1) {
            throw new InvalidArgumentException('Sign in again before saving.');
        }
        $data = $this->validator->validate($input, $today);
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Measurement creation must own its transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            $child = $this->children->lockById((int) $childId);
            if ($child === null) {
                throw new OutOfBoundsException('Child record not found.');
            }
            if ($child['archived_at'] !== null) {
                throw new InvalidArgumentException('Restore this child record before adding a measurement.');
            }
            $age = AgeInMonths::between(new DateTimeImmutable($child['date_of_birth']), new DateTimeImmutable($data['measured_at']));
            $table = $this->references->load();
            $calculator = new GrowthCalculator(new Who2006Standard($table), new Who2007Reference($table));
            try {
                $growth = $calculator->calculate(Sex::from($child['sex']), $age,
                    (float) $data['weight_kg'], (float) $data['height_cm'], MeasurementPosition::from($data['position']));
            } catch (OutOfRangeException $error) {
                throw new InvalidArgumentException('Measurement age is outside the supported WHO reference range.', 0, $error);
            }
            $classification = Classifier::classify($growth);
            $data += ['child_id' => (int) $childId, 'age_months' => $age->months, 'measured_by' => $userId];
            $data['bmi'] = $this->decimal($growth->bmi, true);
            foreach (['wfa' => 'wfaZ', 'hfa' => 'hfaZ', 'wfh' => 'wfhZ', 'bmi' => 'bmiZ'] as $indicator => $property) {
                $data[$indicator . '_z'] = $growth->$property === null ? null : $this->decimal($growth->$property);
                $status = $indicator . 'Status';
                $data[$indicator . '_status'] = $classification->$status?->value;
            }
            // Bind consent to raw precision and current demographics, not just the displayed rounded scores.
            $proof = $data + ['birth_date' => $child['date_of_birth'], 'sex' => $child['sex'],
                'exact_scores' => [$growth->wfaZ, $growth->hfaZ, $growth->wfhZ, $growth->bmiZ]];
            $token = $input['confirmation_token'] ?? null;
            $confirmed = ($input['confirm_implausible'] ?? null) === '1'
                && $this->confirmation->verify($proof, is_string($token) ? $token : null);
            if ($classification->needsConfirmation() && !$confirmed) {
                $this->pdo->rollBack();

                return new MeasurementResult(null, $data, $classification->implausibleIndicators, $this->confirmation->issue($proof));
            }
            $data['implausible_confirmed'] = $classification->needsConfirmation() ? 1 : 0;
            $id = $this->measurements->insert($data);
            $this->audit->log($userId, AuditAction::CREATE, 'measurements', $id, [], $data);
            $this->pdo->commit();
            $this->confirmation->clear();

            return new MeasurementResult($id, $data);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function decimal(float $value, bool $positive = false): string
    {
        $decimal = number_format($value, 2, '.', '');
        if (!is_finite($value) || abs((float) $decimal) > 999.99 || ($positive && (float) $decimal <= 0)) {
            throw new InvalidArgumentException('Calculated values exceed the field limits. Check the measurement values.');
        }

        return (float) $decimal === 0.0 ? '0.00' : $decimal;
    }
}
