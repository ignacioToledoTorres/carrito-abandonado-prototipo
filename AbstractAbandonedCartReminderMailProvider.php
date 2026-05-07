<?php

declare(strict_types=1);

namespace AppBundle\Provider\Mail;

use AppBundle\Entity\EmailType;
use AppBundle\Entity\TenantCheckout;
use AppBundle\Entity\TenantCheckoutItem;
use AppBundle\Enum\CheckoutStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;

abstract class AbstractAbandonedCartReminderMailProvider extends AbstractEmailProvider
{
    public function __construct(
        private readonly string $serviceCode,
        protected readonly EntityManager $entityManager,
    ) {
    }

    abstract protected function getDaysThreshold(): int;

    abstract protected function getEmailType(): int;

    abstract protected function getReminderDqlField(): string;

    abstract protected function getReminderColumnName(): string;

    abstract protected function buildBody(array $entities = []): string;

    abstract protected function buildSubject(array $entities = []): string;

    public function getTemplateName(): string
    {
        return 'custom_content';
    }

    public function isEnabled(bool $force = false): bool
    {
        $now = new \DateTime('now', new \DateTimeZone('America/Santiago'));
        $sendTime = new \DateTime('10:00');

        return $force || ($now->format('Hi') === $sendTime->format('Hi'));
    }

    /** @return class-string */
    public function getEntityClass(): string
    {
        return TenantCheckout::class;
    }

    public function getCode(): string
    {
        return $this->serviceCode;
    }

    /** @param array<mixed> $entities */
    public function getFromName(array $entities = []): string
    {
        return $this->entityManager->getRepository(EmailType::class)
            ->findEntityTypeByLanguage(current($entities)->getUser()->getLanguageDefined(), $this->getEmailType())
            ->getFromName();
    }

    /** @param array<mixed> $entities */
    public function getFromEmail(array $entities = []): string
    {
        return $this->entityManager->getRepository(EmailType::class)
            ->findEntityTypeByLanguage(current($entities)->getUser()->getLanguageDefined(), $this->getEmailType())
            ->getFromEmail();
    }

    /** @param array<mixed> $entities */
    public function getToEmail(array $entities = []): string
    {
        return $entities[0]->getUser()->getEmail();
    }

    /** @param array<mixed> $entities */
    public function getToName(array $entities = []): string
    {
        return $entities[0]->getUser()->getFirstName();
    }

    /** @param array<mixed> $entities */
    public function buildParameters(array $entities = []): array
    {
        return [];
    }

    public function addQueryFields(QueryBuilder $builder, string $rootAlias): QueryBuilder
    {
        return $builder
            ->join('e.user', 'user')
            ->join('e.tenant', 'tenant');
    }

    public function selectQueryFilter(
        QueryBuilder $builder,
        string $rootAlias,
        \DateTimeImmutable $dateTimeExecution,
        bool $force = false,
    ): QueryBuilder {
        $threshold = $dateTimeExecution->modify(sprintf('-%d days', $this->getDaysThreshold()));
        $reminderField = $this->getReminderDqlField();

        $builder
            ->andWhere('e.status = :status')
            ->andWhere('e.updatedAt < :threshold')
            ->andWhere(sprintf('e.%s IS NULL', $reminderField))
            ->andWhere("user.email IS NOT NULL AND user.email != ''")
            ->andWhere('user.enabled = :enabled')
            ->andWhere(sprintf(
                'EXISTS (SELECT item FROM %s item WHERE item.checkout = e AND item.status = :itemStatus)',
                TenantCheckoutItem::class
            ))
            ->setParameters([
                'status' => CheckoutStatus::Pending,
                'threshold' => $threshold,
                'enabled' => true,
                'itemStatus' => CheckoutStatus::Pending,
            ]);

        return $builder;
    }

    public function preQueue(): void
    {
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function onQueue(array $queue = [], array &$groupedQueue = []): void
    {
        if (empty($queue)) {
            return;
        }

        $conn = $this->entityManager->getConnection();
        $conn->beginTransaction();

        try {
            $conn->executeStatement(
                sprintf('UPDATE tenant_checkout SET %s = :date WHERE id IN (:ids)', $this->getReminderColumnName()),
                [
                    'ids' => $queue,
                    'date' => new \DateTime(),
                ],
                [
                    'ids' => ArrayParameterType::INTEGER,
                    'date' => Types::DATETIME_MUTABLE,
                ]
            );
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    public function onSending(array $queue = []): void
    {
    }

    public function groupBy(string $rootAlias): ?string
    {
        return null;
    }

    /** @param array<mixed> $entities */
    public function getAttachment(array $entities = []): ?array
    {
        return null;
    }
}
