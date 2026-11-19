<?php

namespace App\Observers;

use App\Models\Borrower;
use App\Services\AuditLogger;

class BorrowerObserver
{
    private const TRACKED = ['nama', 'nama_pt', 'no_rekening', 'no_hp', 'nik'];

    public function __construct(private AuditLogger $audit)
    {
    }

    public function created(Borrower $borrower): void
    {
        $data = [];
        foreach (array_merge(self::TRACKED, ['nomor_anggota']) as $field) {
            $data[$field] = $this->value($field, $borrower->getAttribute($field));
        }

        $this->audit->record('peminjam.dibuat', $borrower, $borrower->branch_id, null, null, $data);
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
            $before[$field] = $this->value($field, $borrower->getOriginal($field));
            $after[$field] = $this->value($field, $borrower->getAttribute($field));
        }

        $this->audit->record('peminjam.diubah', $borrower, $borrower->branch_id, null, $before, $after);
    }

    /** NIK tidak disimpan utuh di audit log (data pribadi): hanya bentuk tersamar. */
    private function value(string $field, mixed $value): mixed
    {
        return $field === 'nik' ? Borrower::mask($value) : $value;
    }
}
