<?php

namespace App\Enums\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Maps a string-backed enum to the rows of its lookup table by slug, so ids
 * are never hard-coded. The slug → id map is loaded once per request.
 */
trait ResolvesLookup
{
    /**
     * Id of this case's row in the lookup table.
     */
    public function id(): int
    {
        return self::lookupIds()[$this->value]
            ?? throw new UnexpectedValueException(sprintf('Slug "%s" is missing from the "%s" table.', $this->value, self::lookupTable()));
    }

    /**
     * Case matching the given lookup row id.
     */
    public static function fromId(int $id): self
    {
        $slug = array_search($id, self::lookupIds(), true);

        if ($slug === false) {
            throw new UnexpectedValueException(sprintf('Id %d is missing from the "%s" table.', $id, self::lookupTable()));
        }

        return self::from($slug);
    }

    /**
     * Lookup table name derived from the enum name, e.g. CardStatus → card_statuses.
     */
    public static function lookupTable(): string
    {
        return Str::snake(Str::pluralStudly(class_basename(self::class)));
    }

    /**
     * @return array<string, int>
     */
    protected static function lookupIds(): array
    {
        return once(fn (): array => DB::table(self::lookupTable())
            ->pluck('id', 'slug')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }
}
