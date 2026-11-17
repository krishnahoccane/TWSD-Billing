<?php

namespace App\Modules\AccessControl\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * The set of organisations a user may act on for one ability.
 *
 * Always derived server-side from role assignments — never from request
 * input. Either "all organisations" (global access) or an explicit id list,
 * which may be empty.
 */
final class OrganisationScope
{
    /**
     * @param  list<int>  $ids
     */
    private function __construct(
        public readonly bool $all,
        public readonly array $ids,
    ) {}

    public static function all(): self
    {
        return new self(true, []);
    }

    /**
     * @param  iterable<int>  $ids
     */
    public static function only(iterable $ids): self
    {
        $ids = array_values(array_unique(array_map('intval', [...$ids])));

        return new self(false, $ids);
    }

    public static function none(): self
    {
        return new self(false, []);
    }

    public function isEmpty(): bool
    {
        return ! $this->all && $this->ids === [];
    }

    public function includes(int $organisationId): bool
    {
        return $this->all || in_array($organisationId, $this->ids, true);
    }

    /**
     * Constrain a query to this scope. An empty scope matches no rows.
     */
    public function apply(Builder $query, string $column = 'organisation_id'): Builder
    {
        if ($this->all) {
            return $query;
        }

        return $query->whereIn($column, $this->ids);
    }
}
