<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Filament\Resources\Loans\LoanResource;
use App\Models\Borrower;
use App\Services\LoanService;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CreateLoan extends CreateRecord
{
    protected static string $resource = LoanResource::class;

    protected static ?string $title = 'Pinjaman baru';

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        $borrower = Borrower::query()->findOrFail($data['borrower_id']);

        // Cabang hanya boleh mencairkan pinjaman untuk peminjam cabangnya sendiri.
        abort_unless($borrower->branch_id === $user->branch_id, 403);

        $foto = array_values($data['foto'] ?? []);

        try {
            return DB::transaction(function () use ($borrower, $data, $user, $foto) {
                // Kontak peminjam dapat diperbarui dari halaman pengajuan (tercatat di audit log lewat observer).
                $borrower->update([
                    'no_hp' => $data['no_hp'],
                    'no_rekening' => $data['no_rekening'] ?? null,
                ]);

                return app(LoanService::class)->disburse(
                    $borrower,
                    (int) $data['pokok'],
                    (float) $data['persen_admin'],
                    (int) $data['tenor_bulan'],
                    Carbon::parse($data['dicairkan_pada']),
                    $user,
                    $foto,
                );
            });
        } catch (ValidationException|UniqueConstraintViolationException $e) {
            // Pinjaman gagal dibuat: hapus foto yang sudah terunggah agar tidak menjadi yatim.
            if ($foto !== []) {
                Storage::disk('local')->delete($foto);
            }

            $message = $e instanceof ValidationException
                ? (string) collect($e->errors())->flatten()->first()
                : 'Peminjam sudah memiliki pinjaman aktif (permintaan bersamaan).';

            Notification::make()->title($message)->danger()->send();

            $this->halt();
        }
    }
}
