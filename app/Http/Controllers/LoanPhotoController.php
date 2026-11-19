<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Foto pinjaman disimpan privat; hanya cabang pemilik dan super admin yang dapat melihatnya. */
class LoanPhotoController extends Controller
{
    public function show(Request $request, int $loan, int $index): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->aktif, 403);

        $model = Loan::withTrashed()->findOrFail($loan);
        $isSuperAdmin = $user->role === Role::SuperAdmin;

        abort_unless($isSuperAdmin || $user->branch_id === $model->branch_id, 403);
        abort_if($model->trashed() && ! $isSuperAdmin, 404);

        $paths = $model->foto ?? [];
        abort_unless(isset($paths[$index]), 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($paths[$index]), 404);

        return $disk->response($paths[$index], null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
