<?php

namespace App\Http\Controllers;

use App\Services\StatementService;
use App\Services\StatementXlsx;
use App\Support\ReportParams;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RekeningKoranController extends Controller
{
    public function excel(Request $request, StatementService $service, StatementXlsx $xlsx): StreamedResponse
    {
        return $xlsx->download($this->statement($request, $service));
    }

    public function print(Request $request, StatementService $service): View
    {
        return view('reports.rekening-koran-print', [
            'statement' => $this->statement($request, $service),
        ]);
    }

    private function statement(Request $request, StatementService $service)
    {
        $user = $request->user();
        abort_unless($user && $user->aktif, 403);

        $params = ReportParams::resolve($request, $user);

        return $service->build($params->branchId, $params->from, $params->to);
    }
}
