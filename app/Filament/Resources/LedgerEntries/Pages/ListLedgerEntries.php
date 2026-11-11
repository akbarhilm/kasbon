<?php

namespace App\Filament\Resources\LedgerEntries\Pages;

use App\Enums\Arah;
use App\Enums\LedgerPos;
use App\Enums\Role;
use App\Filament\Resources\LedgerEntries\LedgerEntryResource;
use App\Models\LedgerEntry;
use App\Services\LedgerService;
use App\Services\WithdrawalService;
use App\Support\Rupiah;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

class ListLedgerEntries extends ListRecords
{
    protected static string $resource = LedgerEntryResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $user = auth()->user();

        if ($user?->role !== Role::Cabang) {
            return null;
        }

        $ledger = app(LedgerService::class);
        $branch = (int) $user->branch_id;

        return sprintf(
            'Saldo akhir %s | Saldo kas %s | Administrasi %s',
            Rupiah::format($ledger->balance($branch)),
            Rupiah::format($ledger->cashBalance($branch)),
            Rupiah::format($ledger->adminBalance($branch)),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->masukAction('saldo_awal', 'Saldo awal', LedgerPos::SaldoAwal)
                // Saldo awal hanya sekali per cabang.
                ->hidden(fn (): bool => LedgerEntry::query()
                    ->where('branch_id', auth()->user()->branch_id)
                    ->where('pos', LedgerPos::SaldoAwal)
                    ->exists()),
            $this->masukAction('tambah_modal', 'Tambah modal', LedgerPos::PenambahanModal),
            $this->tarikSaldoAction(),
        ];
    }

    private function masukAction(string $name, string $label, LedgerPos $pos): Action
    {
        return Action::make($name)
            ->label($label)
            ->visible(fn (): bool => auth()->user()?->role === Role::Cabang)
            ->modalHeading($label)
            ->schema([
                TextInput::make('jumlah')->numeric()->integer()->minValue(1)->prefix('Rp')->required(),
                DateTimePicker::make('waktu')->label('Tanggal dan jam')->seconds(false)->default(fn () => now())->required(),
                TextInput::make('keterangan')->maxLength(255),
            ])
            ->action(function (array $data) use ($pos): void {
                $user = auth()->user();

                app(LedgerService::class)->post(
                    (int) $user->branch_id,
                    $pos,
                    Arah::Masuk,
                    (int) $data['jumlah'],
                    Carbon::parse($data['waktu']),
                    null,
                    null,
                    filled($data['keterangan'] ?? null) ? $data['keterangan'] : $pos->label(),
                    $user->id,
                );

                Notification::make()->title('Tersimpan')->success()->send();
            });
    }

    /**
     * Penarikan saldo dengan pilihan pos: Saldo kas atau Administrasi.
     * Administrasi: jumlah bawaan = seluruh saldo administrasi (tanpa batas periode).
     * Kedua pos tidak boleh melebihi saldo pos itu.
     */
    private function tarikSaldoAction(): Action
    {
        $limit = fn (?string $source): int => app(WithdrawalService::class)
            ->limit((int) auth()->user()->branch_id, $source ?? WithdrawalService::KAS);

        $label = fn (?string $source): string => $source === WithdrawalService::ADMINISTRASI ? 'saldo administrasi' : 'saldo kas';

        return Action::make('tarik_saldo')
            ->label('Tarik saldo')
            ->color('danger')
            ->visible(fn (): bool => auth()->user()?->role === Role::Cabang)
            ->modalHeading('Tarik saldo')
            ->fillForm(fn (): array => [
                'pos' => WithdrawalService::KAS,
                'waktu' => now()->format('Y-m-d H:i'),
            ])
            ->schema([
                Select::make('pos')
                    ->label('Pos')
                    ->options([
                        WithdrawalService::KAS => 'Saldo kas',
                        WithdrawalService::ADMINISTRASI => 'Administrasi',
                    ])
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set) use ($limit): void {
                        $set('jumlah', $state === WithdrawalService::ADMINISTRASI ? $limit($state) : null);
                    }),
                TextInput::make('jumlah')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->prefix('Rp')
                    ->required()
                    ->maxValue(fn (Get $get): int => max(1, $limit($get('pos'))))
                    ->helperText(fn (Get $get): string => 'Maksimal ' . Rupiah::format($limit($get('pos'))) . ' (' . $label($get('pos')) . ').'),
                DateTimePicker::make('waktu')->label('Tanggal dan jam')->seconds(false)->required(),
                TextInput::make('keterangan')->maxLength(255),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    app(WithdrawalService::class)->withdraw(
                        auth()->user(),
                        $data['pos'],
                        (int) $data['jumlah'],
                        Carbon::parse($data['waktu']),
                        $data['keterangan'] ?? null,
                    );
                } catch (ValidationException|AuthorizationException $e) {
                    $message = $e instanceof ValidationException
                        ? (string) collect($e->errors())->flatten()->first()
                        : $e->getMessage();

                    Notification::make()->title($message)->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Penarikan tersimpan')->success()->send();
            });
    }
}
