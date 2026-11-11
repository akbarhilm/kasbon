<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Filament\Resources\Loans\LoanResource;
use App\Models\Borrower;
use App\Services\LoanService;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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

        try {
            return DB::transaction(function () use ($borrower, $data, $user) {
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
                );
            });
        } catch (ValidationException $e) {
            Notification::make()
                ->title((string) collect($e->errors())->flatten()->first())
                ->danger()
                ->send();

            $this->halt();
        }
    }
}
