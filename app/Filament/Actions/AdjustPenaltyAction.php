<?php

namespace App\Filament\Actions;

use App\Enums\Role;
use App\Models\Loan;
use App\Models\Payment;
use App\Services\DendaAdjustmentService;
use App\Support\Rupiah;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Penyesuaian denda (dispensasi) oleh super admin setelah cabang mencatat pembayaran.
 * Dipilih pembayaran (cicilan) yang bersangkutan; nilai baru antara 0 dan denda hitungan sistem.
 */
class AdjustPenaltyAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'sesuaikan_denda';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Sesuaikan denda')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->visible(fn (?Loan $record): bool => $record instanceof Loan
                && ! $record->trashed()
                && auth()->user()?->role === Role::SuperAdmin
                && $record->payments()->where('denda_sistem', '>', 0)->exists())
            ->modalHeading('Sesuaikan denda (dispensasi)')
            ->modalDescription('Untuk permintaan cabang yang dilaporkan lewat telepon. Selisih denda dikoreksi di buku kas cabang dan dicatat di audit log.')
            ->modalSubmitActionLabel('Simpan penyesuaian')
            ->fillForm(function (?Loan $record): array {
                $payment = $record instanceof Loan ? $this->payments($record)->first() : null;

                return ['payment_id' => $payment?->id, 'denda_baru' => $payment?->denda_final];
            })
            ->schema([
                Select::make('payment_id')
                    ->label('Pembayaran')
                    ->options(function (?Loan $record): array {
                        if (! $record instanceof Loan) {
                            return [];
                        }

                        return $this->payments($record)->mapWithKeys(fn (Payment $p): array => [
                            $p->id => 'Cicilan ke-' . ($p->installment?->no ?? 1)
                                . ' | dibayar ' . $p->dibayar_pada->format('d M Y')
                                . ' | denda ' . Rupiah::format($p->denda_final),
                        ])->all();
                    })
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set): void {
                        $set('denda_baru', Payment::query()->find($state)?->denda_final);
                    }),
                TextEntry::make('rincian')
                    ->label('Denda')
                    ->state(function (Get $get): string {
                        $payment = Payment::query()->find($get('payment_id'));

                        return $payment === null ? '' : 'Hitungan sistem ' . Rupiah::format($payment->denda_sistem)
                            . ' | Saat ini ' . Rupiah::format($payment->denda_final);
                    }),
                TextInput::make('denda_baru')
                    ->label('Denda baru (Rp)')
                    ->mask(RawJs::make("\$money(\$input, '.', ',', 0)"))
                    ->stripCharacters(',')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->live(onBlur: true),
                TextEntry::make('dampak')
                    ->label('Dampak ke saldo kas cabang')
                    ->state(function (Get $get): string {
                        $payment = Payment::query()->find($get('payment_id'));
                        $selisih = (int) $get('denda_baru') - (int) ($payment?->denda_final ?? 0);

                        return match (true) {
                            $selisih < 0 => 'Berkurang ' . Rupiah::format(abs($selisih)),
                            $selisih > 0 => 'Bertambah ' . Rupiah::format($selisih),
                            default => 'Tidak ada perubahan',
                        };
                    }),
                Textarea::make('alasan')
                    ->label('Alasan (mis. nama pelapor dan persetujuan)')
                    ->required()
                    ->minLength(5)
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(function (array $data, Action $action, ?Loan $record): void {
                $payment = $record instanceof Loan
                    ? $record->payments()->whereKey($data['payment_id'])->first()
                    : null;

                if ($payment === null) {
                    $action->halt();

                    return;
                }

                try {
                    app(DendaAdjustmentService::class)->adjust($payment, (int) $data['denda_baru'], $data['alasan'], auth()->user());
                } catch (ValidationException|AuthorizationException $e) {
                    $message = $e instanceof ValidationException
                        ? (string) collect($e->errors())->flatten()->first()
                        : $e->getMessage();

                    Notification::make()->title($message)->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Denda disesuaikan')->success()->send();
            });
    }

    /** Pembayaran yang memiliki denda, terbaru lebih dulu. */
    private function payments(Loan $loan)
    {
        return $loan->payments()->with('installment')->where('denda_sistem', '>', 0)->orderByDesc('id')->get();
    }
}
