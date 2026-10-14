<?php

declare(strict_types=1);

namespace Pediacare\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use PDO;
use Throwable;
use Pediacare\Domain\Enums\AuditAction;
use Pediacare\Domain\RegistrationResult;
use Pediacare\Repositories\ChildRepository;
use Pediacare\Repositories\PurokRepository;
use Pediacare\Support\AuditLogger;
use Pediacare\Support\RecordNumberGenerator;
use Pediacare\Validation\ChildValidator;

final class ChildService
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ChildRepository $children,
        private readonly PurokRepository $puroks,
        private readonly ChildValidator $validator,
        private readonly RecordNumberGenerator $numbers,
        private readonly AuditLogger $audit
    ) {
    }

    public function profile(string $id, ?DateTimeImmutable $today = null): ?array
    {
        if (filter_var($id, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) === false) {
            return null;
        }

        return $this->children->findById((int) $id, ($today ?? new DateTimeImmutable('today'))->format('Y-m-d'));
    }

    public function search(array $filters, ?DateTimeImmutable $today = null): array
    {
        $date = ($today ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $total = $this->children->count($filters, $date);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($filters['page'], $pages);
        $offset = ($page - 1) * self::PAGE_SIZE;

        return [
            'children' => $this->children->search($filters, $date, self::PAGE_SIZE, $offset),
            'total' => $total, 'page' => $page, 'pages' => $pages,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($offset + self::PAGE_SIZE, $total),
        ];
    }

    public function register(array $input, int $userId, bool $confirmed = false, ?DateTimeImmutable $today = null): RegistrationResult
    {
        $today ??= new DateTimeImmutable('today');
        $data = $this->validator->validate($input, $today);
        if ($userId < 1 || $this->puroks->findById($data['purok_id']) === null) {
            throw new InvalidArgumentException('Select an existing purok or sitio and try again.');
        }
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Registration must own its transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            // Serialize registrations before the duplicate read, including simultaneous first registrations.
            $number = $this->numbers->generate((int) $today->format('Y'));
            $duplicates = $this->children->duplicates($data);
            if ($duplicates !== [] && !$confirmed) {
                $this->pdo->rollBack();

                return new RegistrationResult(null, $duplicates);
            }
            $data['record_no'] = $number;
            $data['created_by'] = $userId;
            $id = $this->children->insert($data);
            $this->audit->log($userId, AuditAction::CREATE, 'children', $id, [], $data);
            $this->pdo->commit();

            return new RegistrationResult($number, [], $id);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function update(string $id, array $input, int $userId): void
    {
        $data = $this->validator->validate($input, null, false);
        if ($this->puroks->findById($data['purok_id']) === null) {
            throw new InvalidArgumentException('Select an existing purok or sitio and try again.');
        }
        $this->change($id, $data, $userId, AuditAction::UPDATE);
    }

    public function archive(string $id, array $input, int $userId): void
    {
        $this->change($id, [], $userId, AuditAction::ARCHIVE, $this->validator->reason($input));
    }

    public function restore(string $id, array $input, int $userId): void
    {
        $this->change($id, [], $userId, AuditAction::RESTORE, $this->validator->reason($input));
    }

    private function change(string $id, array $data, int $userId, AuditAction $action, ?string $reason = null): void
    {
        if (filter_var($id, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) === false) {
            throw new OutOfBoundsException('Child record not found.');
        }
        if ($userId < 1) {
            throw new InvalidArgumentException('Sign in again before saving.');
        }
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Child changes must own their transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            // Lock before reading the audit before-image or checking archive state.
            $child = $this->children->lockById((int) $id);
            if ($child === null) {
                throw new OutOfBoundsException('Child record not found.');
            }
            if ($action === AuditAction::UPDATE) {
                $this->children->update((int) $id, $data);
            } else {
                $archiving = $action === AuditAction::ARCHIVE;
                if ($archiving === ($child['archived_at'] !== null)) {
                    throw new InvalidArgumentException($archiving
                        ? 'This child record is already archived.' : 'This child record is already active.');
                }
                $data = ['archived_at' => $archiving ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null,
                    'archive_reason' => $archiving ? $reason : null];
                $this->children->setArchive((int) $id, $data['archived_at'], $data['archive_reason']);
            }
            $before = array_intersect_key($child, $data);
            if ($action === AuditAction::UPDATE) {
                $before['purok_id'] = (int) $before['purok_id'];
            }
            $after = $data;
            if ($action === AuditAction::RESTORE) {
                // Keep the restore reason in the audit trail after clearing current archive fields.
                $after['restore_reason'] = $reason;
            }
            $this->audit->log($userId, $action, 'children', (int) $id, $before, $after);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
