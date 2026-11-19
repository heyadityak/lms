<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use LogicException;

/**
 * Rows are written once (models also set UPDATED_AT = null) and never changed (ledger D-02, user-admin audit D-22). Corrections are new rows.
 * shortcut: guards Eloquent model writes only; query-builder mass updates are not blocked, add a DB trigger if that matters.
 */
trait AppendOnly
{
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException(static::class.' is append-only; write a new row instead.');
        }

        return parent::save($options);
    }

    public function delete(): never
    {
        throw new LogicException(static::class.' is append-only and cannot be deleted.');
    }
}
