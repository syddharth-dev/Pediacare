<?php

declare(strict_types=1);

namespace Pediacare\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use PDO;
use Pediacare\Domain\Enums\AuditAction;
use Pediacare\Repositories\ChildRepository;
use Pediacare\Repositories\SupplementRepository;
use Pediacare\Repositories\VisitRepository;
use Pediacare\Support\AuditLogger;
use Pediacare\Validation\VisitValidator;
use Throwable;

final class VisitService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ChildRepository $children,
        private readonly VisitRepository $visits,
        private readonly SupplementRepository $supplements,
        private readonly VisitValidator $validator,
        private readonly AuditLogger $audit
    ) {
    }

    public function record(string $childId, array $input, int $actor, ?DateTimeImmutable $today = null): int
    {
        if (preg_match('/\A[1-9][0-9]{0,9}\z/', $childId) !== 1 || (float) $childId > 4294967295) {
            throw new OutOfBoundsException('Child record not found.');
        }
        if ($actor < 1) {
            throw new InvalidArgumentException('Sign in again before saving.');
        }
        $data = $this->validator->validate($input, $today);
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Visit creation must own its transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            $child = $this->children->lockById((int) $childId) ?? throw new OutOfBoundsException('Child record not found.');
            if ($child['archived_at'] !== null) {
                throw new InvalidArgumentException('Restore this child record before recording a visit.');
            }
            if ($data['visit']['visit_date'] < $child['date_of_birth']) {
                throw new InvalidArgumentException('Visit date must be on or after birth.');
            }
            foreach ($data['supplements'] as $row) {
                if ($row['date_given'] < $child['date_of_birth']) {
                    throw new InvalidArgumentException('Supplement dates must be on or after birth.');
                }
            }
            $visit = $data['visit'] + ['child_id' => (int) $childId, 'attended_by' => $actor];
            $id = $this->visits->insert($visit);
            $this->audit->log($actor, AuditAction::CREATE, 'visits', $id, [], $visit);
            foreach ($data['supplements'] as $row) {
                $row += ['visit_id' => $id];
                $supplementId = $this->supplements->insert($row);
                $this->audit->log($actor, AuditAction::CREATE, 'supplements', $supplementId, [], $row);
            }
            $this->pdo->commit();

            return $id;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
