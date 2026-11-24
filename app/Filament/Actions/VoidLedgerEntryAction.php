<?php

namespace App\Filament\Actions;

use App\Enums\Role;
use App\Models\LedgerEntry;
use App\Services\LedgerVoidService;
use App\Support\Rupiah;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Pembatalan entri buku kas manual oleh super admin.
 * Record diterima lewat injeksi parameter ($record) agar tombol tampil pada tiap baris tabel.
 */
class VoidLedgerEntryAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'batalkan_entri';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Batalkan entri')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(function (?LedgerEntry $record): bool {
                if (! $record instanceof LedgerEntry) {
                    return false;
                }

                return auth()->user()?->role === Role::SuperAdmin
                    && LedgerVoidService::isVoidable($record)
                    && ! $record->void;
            })
            ->modalHeading('Batalkan entri buku kas')
            ->modalDescription(function (?LedgerEntry $record): string {
                if (! $record instanceof LedgerEntry) {
                    return '';
                }

                $impact = app(LedgerVoidService::class)->impact($record);

                return sprintf(
                    '%s %s akan dibalik dengan entri koreksi (entri asli tetap tersimpan). %s cabang %s %s (dari %s menjadi %s). Tindakan ini tercatat di audit log dan tidak dapat dibatalkan.',
                    $record->pos->label(),
                    Rupiah::format($record->jumlah),
                    ucfirst($impact['kantong']),
                    $impact['perubahan'] >= 0 ? 'bertambah' : 'berkurang',
                    Rupiah::format(abs($impact['perubahan'])),
                    Rupiah::format($impact['sekarang']),
                    Rupiah::format($impact['setelah']),
                );
            })
            ->modalSubmitActionLabel('Batalkan entri')
            ->schema([
                Textarea::make('alasan')
                    ->label('Alasan pembatalan (mis. nama pelapor dan persetujuan)')
                    ->required()
                    ->minLength(5)
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(function (array $data, Action $action, ?LedgerEntry $record): void {
                if (! $record instanceof LedgerEntry) {
                    $action->halt();

                    return;
                }

                try {
                    app(LedgerVoidService::class)->void($record, $data['alasan'], auth()->user());
                } catch (ValidationException|AuthorizationException $e) {
                    $message = $e instanceof ValidationException
                        ? (string) collect($e->errors())->flatten()->first()
                        : $e->getMessage();

                    Notification::make()->title($message)->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Entri dibatalkan dan saldo dikoreksi')->success()->send();
            });
    }
}
