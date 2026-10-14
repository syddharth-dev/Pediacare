<?php

declare(strict_types=1);

namespace Pediacare\Services;

use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use PDO;
use PDOException;
use Pediacare\Domain\Enums\AuditAction;
use Pediacare\Repositories\VaccineScheduleRepository;
use Pediacare\Support\AuditLogger;
use Pediacare\Validation\VaccineScheduleValidator;
use Throwable;

final class VaccineScheduleService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly VaccineScheduleRepository $schedule,
        private readonly VaccineScheduleValidator $validator,
        private readonly AuditLogger $audit
    ) {
    }

    public function all(): array
    {
        return $this->schedule->all();
    }

    public function vaccine(string $id): array
    {
        return $this->schedule->vaccine($this->validator->id($id)) ?? throw new OutOfBoundsException('Vaccine not found.');
    }

    public function dose(string $id): array
    {
        return $this->schedule->dose($this->validator->id($id)) ?? throw new OutOfBoundsException('Dose not found.');
    }

    public function saveVaccine(?string $id, array $input, int $actor): int
    {
        $data = $this->validator->vaccine($input, $id === null);
        $value = $id === null ? null : $this->validator->id($id);

        return $this->transaction(function () use ($value, $data, $actor): int {
            $before = [];
            if ($value === null) {
                $saved = $this->schedule->insertVaccine($data);
            } else {
                $before = $this->schedule->vaccine($value, true) ?? throw new OutOfBoundsException('Vaccine not found.');
                $this->schedule->updateVaccine($value, $data);
                $saved = $value;
                $before = array_intersect_key($before, $data);
            }
            $this->audit->log($actor, $value === null ? AuditAction::CREATE : AuditAction::UPDATE, 'vaccines', $saved, $before, $data);

            return $saved;
        });
    }

    public function saveDose(?string $id, ?string $vaccineId, array $input, int $actor): int
    {
        $data = $this->validator->dose($input);
        $value = $id === null ? null : $this->validator->id($id);
        $parent = $value === null ? $this->validator->id($vaccineId ?? '') : null;

        return $this->transaction(function () use ($value, $parent, $data, $actor): int {
            $before = [];
            if ($value === null) {
                $this->schedule->vaccine($parent, true) ?? throw new OutOfBoundsException('Vaccine not found.');
                $data['vaccine_id'] = $parent;
                $saved = $this->schedule->insertDose($data);
            } else {
                $before = $this->schedule->dose($value, true) ?? throw new OutOfBoundsException('Dose not found.');
                if ((int) $before['dose_number'] !== $data['dose_number'] && $this->schedule->doseUsed($value)) {
                    throw new InvalidArgumentException('This dose already has vaccination records. Keep its dose number and add a new dose if needed.');
                }
                $this->schedule->updateDose($value, $data);
                $saved = $value;
                $before = array_intersect_key($before, $data);
            }
            $this->audit->log($actor, $value === null ? AuditAction::CREATE : AuditAction::UPDATE, 'schedule_doses', $saved, $before, $data);

            return $saved;
        });
    }

    private function transaction(callable $save): int
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Schedule changes require their own transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            $id = $save();
            $this->pdo->commit();

            return $id;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062) {
                throw new InvalidArgumentException('That vaccine code or dose number already exists. Choose a different value.');
            }
            throw $error;
        }
    }
}
