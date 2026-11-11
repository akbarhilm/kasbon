<?php

namespace App\Filament\Actions;

use App\Enums\LoanStatus;
use App\Enums\Role;
use App\Models\Loan;
use App\Services\PaymentService;
use App\Services\PenaltyCalculator;
use App\Support\Rupiah;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class LunasiAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'lunasi';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Lunasi')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (): bool => auth()->user()?->role === Role::Cabang
                && $this->getRecord()?->status === LoanStatus::Aktif)
            ->modalHeading('Pelunasan pinjaman')
            ->modalSubmitActionLabel('Simpan pelunasan')
            ->fillForm(fn (): array => [
                'dibayar_pada' => now()->format('Y-m-d H:i'),
                'denda_final' => $this->dendaSistem(now()),
            ])
            ->schema([
                DateTimePicker::make('dibayar_pada')
                    ->label('Tanggal dan jam bayar')
                    ->seconds(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        $set('denda_final', $this->dendaSistem($this->paidAt($get)));
                    }),
                TextInput::make('denda_final')
                    ->label('Denda yang dibebankan (Rp)')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->required()
                    ->live(onBlur: true)
                    ->helperText('Boleh diubah, termasuk menjadi 0 (dispensasi). Alasan wajib diisi jika berbeda dari hitungan sistem.'),
                Textarea::make('alasan_penyesuaian')
                    ->label('Alasan penyesuaian denda')
                    ->rows(2)
                    ->required(fn (Get $get): bool => (int) $get('denda_final') !== $this->dendaSistem($this->paidAt($get))),
                TextEntry::make('rincian')
                    ->label('Rincian')
                    ->state(fn (Get $get): string => 'Pokok ' . Rupiah::format(($this->getRecord()?->pokok ?? 0))
                        . ' | Denda sistem ' . Rupiah::format($this->dendaSistem($this->paidAt($get)))
                        . ' | Total dibayar ' . Rupiah::format(($this->getRecord()?->pokok ?? 0) + (int) $get('denda_final'))),
            ])
            ->action(function (array $data, Action $action): void {
                $loan = $this->getRecord();

                if (! $loan instanceof Loan) {
                    $action->halt();

                    return;
                }

                try {
                    app(PaymentService::class)->settle(
                        $loan,
                        Carbon::parse($data['dibayar_pada']),
                        (int) $data['denda_final'],
                        $data['alasan_penyesuaian'] ?? null,
                        auth()->user(),
                    );
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title((string) collect($e->errors())->flatten()->first())
                        ->danger()
                        ->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Pinjaman dilunasi')->success()->send();
            });
    }

    private function paidAt(Get $get): CarbonInterface
    {
        return filled($get('dibayar_pada')) ? Carbon::parse($get('dibayar_pada')) : now();
    }

    private function dendaSistem(CarbonInterface $at): int
    {
        $loan = $this->getRecord();

        if (! $loan instanceof Loan) {
            return 0;
        }

        return PenaltyCalculator::amount(
            $loan->biaya_admin,
            PenaltyCalculator::periods($loan->jatuh_tempo, $at),
        );
    }
}
