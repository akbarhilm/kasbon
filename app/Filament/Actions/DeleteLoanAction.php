<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Loans\LoanResource;
use App\Models\Loan;
use App\Services\LoanDeletionService;
use App\Support\Rupiah;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/** Hapus pinjaman: hanya super admin (LoanPolicy::delete), alasan wajib, saldo dikoreksi otomatis. */
class DeleteLoanAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'hapus_pinjaman';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Hapus pinjaman')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->authorize('delete')
            ->visible(fn (): bool => ($loan = $this->getRecord()) instanceof Loan && ! $loan->trashed())
            ->modalHeading('Hapus pinjaman')
            ->modalDescription(function (): string {
                $loan = $this->getRecord();

                if (! $loan instanceof Loan) {
                    return '';
                }

                $impact = app(LoanDeletionService::class)->impact($loan);
                $arah = $impact['perubahan'] >= 0 ? 'bertambah' : 'berkurang';

                return sprintf(
                    '%d entri buku kas akan dibalik. Saldo cabang %s %s (dari %s menjadi %s). Tindakan ini tercatat di audit log dan tidak dapat dibatalkan.',
                    $impact['count'],
                    $arah,
                    Rupiah::format(abs($impact['perubahan'])),
                    Rupiah::format($impact['sekarang']),
                    Rupiah::format($impact['setelah']),
                );
            })
            ->modalSubmitActionLabel('Hapus pinjaman')
            ->schema([
                Textarea::make('alasan')
                    ->label('Alasan penghapusan')
                    ->required()
                    ->minLength(5)
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(function (array $data, Action $action): void {
                $loan = $this->getRecord();

                if (! $loan instanceof Loan) {
                    $action->halt();

                    return;
                }

                try {
                    app(LoanDeletionService::class)->delete($loan, $data['alasan'], auth()->user());
                } catch (ValidationException|AuthorizationException $e) {
                    $message = $e instanceof ValidationException
                        ? (string) collect($e->errors())->flatten()->first()
                        : $e->getMessage();

                    Notification::make()->title($message)->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Pinjaman dihapus dan saldo dikoreksi')->success()->send();
            })
            ->successRedirectUrl(fn (): string => LoanResource::getUrl('index'));
    }
}
