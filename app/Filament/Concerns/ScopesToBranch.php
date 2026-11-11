<?php

namespace App\Filament\Concerns;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * User cabang hanya melihat data cabangnya. Super admin melihat semua cabang,
 * termasuk data yang sudah dihapus (soft delete) agar dapat difilter lewat TrashedFilter.
 */
trait ScopesToBranch
{
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && $user->role === Role::Cabang) {
            $query->where($query->getModel()->getTable() . '.branch_id', $user->branch_id);
        } elseif ($user && $user->role === Role::SuperAdmin
            && in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(static::getModel()), true)) {
            $query->withoutGlobalScopes([SoftDeletingScope::class]);
        }

        return $query;
    }
}
