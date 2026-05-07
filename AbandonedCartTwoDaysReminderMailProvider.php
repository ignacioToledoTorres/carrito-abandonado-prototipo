<?php

declare(strict_types=1);

namespace AppBundle\Provider\Mail;

use AppBundle\Entity\Tenant;

final class AbandonedCartTwoDaysReminderMailProvider extends AbstractAbandonedCartReminderMailProvider
{
    protected function getDaysThreshold(): int
    {
        return 2;
    }

    protected function getEmailType(): int
    {
        return Tenant::TENANT_EMAIL_CONFIGURATION_ABANDONED_CART_TWO_DAYS;
    }

    protected function getReminderDqlField(): string
    {
        return 'reminderTwoDaysSentAt';
    }

    protected function getReminderColumnName(): string
    {
        return 'reminder_two_days_sent_at';
    }
}
