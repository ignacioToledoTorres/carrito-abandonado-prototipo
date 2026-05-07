<?php

declare(strict_types=1);

namespace AppBundle\Provider\Mail;

use AppBundle\Entity\Tenant;

final class AbandonedCartSixDaysReminderMailProvider extends AbstractAbandonedCartReminderMailProvider
{
    protected function getDaysThreshold(): int
    {
        return 6;
    }

    protected function getEmailType(): int
    {
        return Tenant::TENANT_EMAIL_CONFIGURATION_ABANDONED_CART_SIX_DAYS;
    }

    protected function getReminderDqlField(): string
    {
        return 'reminderSixDaysSentAt';
    }

    protected function getReminderColumnName(): string
    {
        return 'reminder_six_days_sent_at';
    }
}
