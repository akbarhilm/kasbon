<?php

namespace App\Filament\Actions;

use App\Enums\LoanStatus;
use App\Enums\Role;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Services\PaymentService;
use App\Services\PenaltyCalculator;
use App\Support\Rupiah;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Pembayaran oleh cabang: membayar cicilan berikutnya (tenor 1 bulan = pelunasan).
 * Cicilan dibayar berurutan dan penuh. Denda dihitung otomatis dan TIDAK dapat diubah cabang;
 * dispensasi dilaporkan ke super admin setelah pembayaran.
 */
class LunasiAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'lunasi';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Bayar')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (?Loan $record): bool => $record instanceof Loan
                && auth()->user()?->role === Role::Cabang
                && $record->status === LoanStatus::Aktif)
            ->modalHeading('Pembayaran pinjaman')
            ->modalDescription('Cicilan dibayar berurutan dan harus penuh (tidak ada pembayaran di bawah nominal cicilan). Denda dihitung otomatis dan tidak dapat diubah cabang. Jika ada dispensasi, catat pembayaran dengan denda yang tampil, lalu laporkan ke super admin untuk disesuaikan.')
            ->modalSubmitActionLabel('Simpan pembayaran')
            ->fillForm(fn (): array => ['dibayar_pada' => now()->format('Y-m-d H:i')])
            ->schema([
                DateTimePicker::make('dibayar_pada')
                    ->label('Tanggal dan jam bayar')
                    ->seconds(false)
                    ->required()
                    ->live(),
                TextEntry::make('rincian')
                    ->label('Rincian')
                    ->state(function (Get $get, ?Loan $record): string {
                        if (! $record instanceof Loan) {
                            return '';
                        }

                        $next = $this->next($record);

                        if ($next === null) {
                            return 'Tidak ada cicilan yang belum dibayar.';
                        }

                        $paidAt = filled($get('dibayar_pada')) ? Carbon::parse($get('dibayar_pada')) : now();
                        $denda = $this->denda($record, $next, $paidAt);
                        $total = $record->installments()->count();

                        return 'Cicilan ke-' . $next->no . ' dari ' . $total
                            . ' | Jatuh tempo ' . $next->jatuh_tempo->translatedFormat('d M Y')
                            . ' | Pokok ' . Rupiah::format($next->pokok)
                            . ' | Denda ' . Rupiah::format($denda)
                            . ' | Total dibayar ' . Rupiah::format($next->pokok + $denda)
                            . ' | Denda mulai berlaku ' . PenaltyCalculator::penaltyStartsOn($next->jatuh_tempo)->translatedFormat('d M Y');
                    }),
            ])
            ->action(function (array $data, Action $action, ?Loan $record): void {
                if (! $record instanceof Loan) {
                    $action->halt();

                    return;
                }

                try {
                    app(PaymentService::class)->payInstallment($record, Carbon::parse($data['dibayar_pada']), auth()->user());
                } catch (ValidationException|AuthorizationException $e) {
                    $message = $e instanceof ValidationException
                        ? (string) collect($e->errors())->flatten()->first()
                        : $e->getMessage();

                    Notification::make()->title($message)->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Pembayaran tersimpan')->success()->send();
            });
    }

    private function next(Loan $loan): ?LoanInstallment
    {
        return $loan->installments()->whereNull('payment_id')->orderBy('no')->first();
    }

    private function denda(Loan $loan, LoanInstallment $installment, CarbonInterface $at): int
    {
        return PenaltyCalculator::amount($loan->biaya_admin, PenaltyCalculator::periods($installment->jatuh_tempo, $at));
    }
}
