<?php

namespace App\Observers;

use App\Models\Borrower;
use App\Services\AuditLogger;

class BorrowerObserver
{
    private const TRACKED = ['nama', 'nama_pt', 'no_rekening', 'no_hp'];

    public function __construct(private AuditLogger $audit)
    {
    }

    public function created(Borrower $borrower): void
    {
        $this->audit->record('peminjam.dibuat', $borrower, $borrower->branch_id, null, null, $borrower->only(self::TRACKED));
    }

    public function updated(Borrower $borrower): void
    {
        $fields = array_values(array_intersect(array_keys($borrower->getChanges()), self::TRACKED));

        if ($fields === []) {
            return;
        }

        $before = [];
        $after = [];
        foreach ($fields as $field) {
            $before[$field] = $borrower->getOriginal($field);
            $after[$field] = $borrower->getAttribute($field);
        }

        $this->audit->record('peminjam.diubah', $borrower, $borrower->branch_id, null, $before, $after);
    }
}
