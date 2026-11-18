<?php

namespace Rta\ElasticLogs;

/**
 * Kept for apps written against v1.0. The split is now set by the "rotation"
 * config (monthly by default), so this behaves exactly like LogSearchService.
 *
 * @deprecated Extend LogSearchService instead.
 */
abstract class MonthlyLogSearchService extends LogSearchService
{
    /** @deprecated Use indices(). */
    public function monthlyIndices(): array
    {
        return $this->indices();
    }

    /** @deprecated Use deletePeriod(). */
    public function deleteMonth($month): bool
    {
        return $this->deletePeriod($month);
    }

    /** @deprecated Use deleteOlderThan(). */
    public function deleteMonthsOlderThan(int $months): array
    {
        return $this->deleteOlderThan($months);
    }
}
