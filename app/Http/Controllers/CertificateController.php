<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class CertificateController extends Controller
{
    public function download(Certificate $certificate): Response
    {
        $this->authorize('download', $certificate);

        if (! preg_match('/\Acertificates\/[0-9A-HJKMNP-TV-Z]{26}\.pdf\z/', $certificate->pdf_path)) {
            abort(404);
        }

        $disk = Storage::disk('private');

        abort_unless($disk->exists($certificate->pdf_path), 404);

        $filename = basename($certificate->pdf_path);

        return response($disk->get($certificate->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
